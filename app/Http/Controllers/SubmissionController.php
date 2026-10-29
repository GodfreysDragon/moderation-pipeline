<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Http\Presenters\SubmissionPresenter;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Submission;
use App\Moderation\ModerationService;
use App\Moderation\SubmissionIntake;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    /**
     * Members see their own submissions; staff see everyone's, filterable by status.
     * Search matches the caption, and for staff also the text read in the image.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = SubmissionStatus::tryFrom((string) $request->query('status'));
        $search = mb_substr(trim((string) $request->query('q')), 0, 100);
        $pattern = '%'.$search.'%';

        $submissions = Submission::query()
            ->with('user:id,name')
            ->when(! $user->isStaff(), fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('caption', $pattern)
                ->when($user->isStaff(), fn ($q) => $q->orWhereLike('ai_image_text', $pattern))))
            ->latest('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Submission $s) => SubmissionPresenter::summary($s, $user));

        return Inertia::render('submissions/Index', [
            'submissions' => $submissions,
            'filters' => ['status' => $status?->value, 'q' => $search !== '' ? $search : null],
            'scope' => $user->isStaff() ? 'all' : 'mine',
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('submissions/Create', [
            'maxUploadKb' => config('moderation.max_upload_kb'),
        ]);
    }

    public function store(StoreSubmissionRequest $request, SubmissionIntake $intake): RedirectResponse
    {
        $submission = $intake->accept($request->user(), $request->validated('caption'), $request->file('image'));

        return to_route('submissions.show', $submission);
    }

    public function show(Request $request, Submission $submission): Response
    {
        Gate::authorize('view', $submission);

        $submission->load(['steps', 'tasks.claimant', 'tasks.resolver', 'user', 'decider', 'policyVersion']);

        return Inertia::render('submissions/Show', [
            'submission' => SubmissionPresenter::detail($submission, $request->user()),
        ]);
    }

    /**
     * Images live on a private disk and are only served to people allowed to see them.
     */
    public function media(Submission $submission, string $variant): StreamedResponse
    {
        Gate::authorize('view', $submission);

        $path = match ($variant) {
            'image' => $submission->image_path,
            'thumb' => $submission->thumbnail_path,
            default => null,
        };

        abort_if($path === null, 404);

        return Storage::disk(config('moderation.disk'))->response($path, headers: [
            'Content-Type' => $submission->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function appeal(Request $request, Submission $submission, ModerationService $moderation): RedirectResponse
    {
        Gate::authorize('appeal', $submission);

        $validated = $request->validate([
            'message' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $moderation->appeal($submission, $validated['message']);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Appeal sent. A different moderator will review it.']);

        return back();
    }
}
