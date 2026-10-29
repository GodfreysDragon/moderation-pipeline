<?php

use App\Enums\SubmissionStatus;
use App\Models\Submission;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Webhooks\Signature;
use App\Webhooks\UnsafeWebhookUrl;
use App\Webhooks\UrlGuard;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Lottery;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Storage::fake('local');
    Lottery::alwaysLose();

    // DNS answers for the tests: hooks.example resolves publicly, internal.example privately.
    $this->app->instance(UrlGuard::class, new UrlGuard(lookup: fn (string $host) => match ($host) {
        'hooks.example' => ['93.184.216.34'],
        'internal.example' => ['10.0.0.5'],
        default => [],
    }));

    $this->user = User::factory()->create();
});

afterEach(fn () => Lottery::determineResultNormally());

function endpointFor(User $user, string $url = 'https://hooks.example/moderation'): WebhookEndpoint
{
    return $user->webhookEndpoints()->create(['url' => $url, 'secret' => 'whsec_test']);
}

test('a decision is POSTed to the uploader\'s endpoint, signed', function () {
    Http::fake(['hooks.example/*' => Http::response(status: 204)]);
    endpointFor($this->user);

    $this->actingAs($this->user)->post(route('submissions.store'), ['caption' => 'Sunset over the harbour'])->assertRedirect();
    $submission = Submission::query()->sole();

    Http::assertSent(function (Request $request) use ($submission) {
        $body = json_decode($request->body(), true);

        return $request->url() === 'https://hooks.example/moderation'
            && $request->hasHeader('Moderation-Event', 'submission.decided')
            && Signature::verify($request->header(Signature::HEADER)[0], $request->body(), 'whsec_test')
            && $body['event'] === 'submission.decided'
            && $body['data']['id'] === $submission->id
            && $body['data']['status'] === 'approved';
    });

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status())->toBe('delivered')
        ->and($delivery->response_status)->toBe(204)
        ->and($delivery->submission_id)->toBe($submission->id);
});

test('a later human decision sends another event; staying in review sends none', function () {
    Http::fake();
    endpointFor($this->user);
    $submission = Submission::factory()->for($this->user)->create(['status' => SubmissionStatus::Processing]);

    $submission->update(['status' => SubmissionStatus::PendingReview]);
    expect(WebhookDelivery::query()->count())->toBe(0);

    $submission->update(['status' => SubmissionStatus::Rejected, 'decision_reason' => 'spam']);
    expect(WebhookDelivery::query()->sole()->payload['data']['decision']['reason'])->toBe('spam');
});

test('other users\' decisions are not sent', function () {
    Http::fake();
    endpointFor(User::factory()->create());

    $this->actingAs($this->user)->post(route('submissions.store'), ['caption' => 'Hello'])->assertRedirect();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->count())->toBe(0);
});

test('a failing receiver is recorded and left for a retry', function () {
    Http::fake(['hooks.example/*' => Http::response('down', 503)]);
    endpointFor($this->user);

    $this->actingAs($this->user)->post(route('submissions.store'), ['caption' => 'Hello'])->assertRedirect();

    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->status())->toBe('retrying')
        ->and($delivery->attempts)->toBe(1)
        ->and($delivery->error)->toBe('The endpoint answered HTTP 503.');
});

test('an endpoint that now resolves to a private address is never called', function () {
    Http::fake();
    endpointFor($this->user, 'https://internal.example/hook');

    $this->actingAs($this->user)->post(route('submissions.store'), ['caption' => 'Hello'])->assertRedirect();

    Http::assertNothingSent();
    expect(WebhookDelivery::query()->sole())
        ->status()->toBe('failed')
        ->error->toContain('private or reserved address');
});

test('endpoints are checked when added, limited per user and private to their owner', function () {
    $confirmed = ['auth.password_confirmed_at' => time()];
    $this->actingAs($this->user)->withSession($confirmed);

    $this->post(route('api-access.webhooks.store'), ['url' => 'http://127.0.0.1/hook'])->assertSessionHasErrors(['url' => '127.0.0.1 points to a private or reserved address (127.0.0.1). Webhooks can only go to public servers.']);
    $this->post(route('api-access.webhooks.store'), ['url' => 'https://internal.example/hook'])->assertSessionHasErrors('url');
    $this->post(route('api-access.webhooks.store'), ['url' => 'https://hooks.example/a'])->assertSessionHasNoErrors();

    $endpoint = $this->user->webhookEndpoints()->sole();
    expect($endpoint->secret)->toStartWith('whsec_');

    endpointFor($this->user);
    endpointFor($this->user);
    $this->post(route('api-access.webhooks.store'), ['url' => 'https://hooks.example/d'])->assertSessionHasErrors('url');

    $this->actingAs(User::factory()->create())->withSession($confirmed)
        ->delete(route('api-access.webhooks.destroy', $endpoint))->assertNotFound();
});

test('the settings page creates tokens once and sends test events', function () {
    Http::fake();
    $endpoint = endpointFor($this->user);
    $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);

    $this->post(route('api-access.tokens.store'), ['name' => 'Photo app', 'abilities' => ['submissions:write']])
        ->assertSessionHas('inertia.flash_data.secret', fn (array $secret) => str_contains($secret['value'], '|'));
    $this->post(route('api-access.webhooks.ping', $endpoint))->assertRedirect();

    $this->get(route('api-access.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/ApiAccess')
        ->where('tokens.0.name', 'Photo app')
        ->where('tokens.0.abilities', ['submissions:write'])
        ->where('deliveries.0.event', 'ping')
        ->where('deliveries.0.status', 'delivered')
        ->missing('endpoints.0.secret'));

    $this->delete(route('api-access.tokens.destroy', $this->user->tokens()->sole()->id))->assertRedirect();
    expect($this->user->tokens()->count())->toBe(0);
});

test('the guard only allows public http(s) targets', function (string $url, bool $allowed) {
    $guard = new UrlGuard(lookup: fn () => ['93.184.216.34']);

    if ($allowed) {
        expect($guard->check($url)['ip'])->toBe('93.184.216.34');
    } else {
        expect(fn () => $guard->check($url))->toThrow(UnsafeWebhookUrl::class);
    }
})->with([
    'public host' => ['https://hooks.example/x', true],
    'public IP' => ['http://93.184.216.34:8080/x', true],
    'loopback' => ['http://127.0.0.1/x', false],
    'cloud metadata' => ['http://169.254.169.254/latest', false],
    'private range' => ['https://192.168.1.10/x', false],
    'IPv6 loopback' => ['http://[::1]/x', false],
    'other scheme' => ['ftp://hooks.example/x', false],
    'credentials' => ['https://user:pass@hooks.example/x', false],
]);

test('signatures reject tampering and stale timestamps', function () {
    $header = Signature::make('{"a":1}', 'secret', 1_000_000);

    expect(Signature::verify($header, '{"a":1}', 'secret', now: 1_000_100))->toBeTrue()
        ->and(Signature::verify($header, '{"a":2}', 'secret', now: 1_000_100))->toBeFalse()
        ->and(Signature::verify($header, '{"a":1}', 'other', now: 1_000_100))->toBeFalse()
        ->and(Signature::verify($header, '{"a":1}', 'secret', now: 1_000_400))->toBeFalse();
});
