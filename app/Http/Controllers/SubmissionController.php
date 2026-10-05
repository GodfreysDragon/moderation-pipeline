<?php

namespace App\Http\Controllers;

use App\Enums\SubmissionStatus;
use App\Http\Presenters\SubmissionPresenter;
use App\Models\Submission;
use App\Moderation\ModerationPipeline;
use App\Moderation\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    /**
     * Members see their own submissions; staff see everyone's, filterable by status.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = SubmissionStatus::tryFrom((string) $request->query('status'));

        $submissions = Submission::query()
            ->with('user:id,name')
            ->when(! $user->isStaff(), fn ($q) => $q->where('user_id', $user->id))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(24)
            ->withQueryString()
            ->through(fn (Submission $s) => SubmissionPresenter::summary($s, $user));

        return Inertia::render('submissions/Index', [
            'submissions' => $submissions,
            'filters' => ['status' => $status?->value],
            'scope' => $user->isStaff() ? 'all' : 'mine',
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('submissions/Create', [
            'maxUploadKb' => config('moderation.max_upload_kb'),
        ]);
    }

    /**
     * Accept an upload and hand it to the pipeline. Nothing is analysed in the request.
     */
    public function store(Request $request, ModerationPipeline $pipeline): RedirectResponse
    {
        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:2000', Rule::requiredIf(! $request->hasFile('image'))],
            'image' => ['nullable', 'file', 'mimes:jpg,jpeg,png,gif,webp', 'max:'.config('moderation.max_upload_kb')],
        ], [
            'caption.required' => 'Add an image, a caption, or both.',
        ]);

        $upload = $request->file('image');
        $path = $upload?->storeAs('uploads', (string) Str::uuid(), config('moderation.disk'));

        $submission = $request->user()->submissions()->create([
            'caption' => filled($validated['caption'] ?? null) ? trim($validated['caption']) : null,
            'upload_path' => $path ?: null,
            'original_filename' => $upload ? mb_substr(basename($upload->getClientOriginalName()), 0, 200) : null,
        ]);

        $pipeline->start($submission);

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
