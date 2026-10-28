<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Enums\Decision;
use App\Enums\TaskKind;
use App\Exceptions\ClaimLostException;
use App\Http\Presenters\SubmissionPresenter;
use App\Models\ReviewTask;
use App\Moderation\ModerationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReviewController extends Controller
{
    public function __construct(private readonly ModerationService $moderation) {}

    /**
     * The open queue, most urgent first.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('moderate');

        $user = $request->user();
        $kinds = $this->moderation->kindsFor($user);
        $kind = TaskKind::tryFrom((string) $request->query('kind'));

        $tasks = ReviewTask::query()
            ->open()
            ->with(['submission.user:id,name', 'claimant:id,name'])
            ->whereIn('kind', array_map(fn (TaskKind $k) => $k->value, $kinds))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (ReviewTask $task) => [
                ...SubmissionPresenter::task($task),
                'excluded' => $task->excluded_reviewer_id === $user->id,
                'mine' => $task->isClaimedBy($user),
                'submission' => SubmissionPresenter::summary($task->submission, $user),
            ]);

        $counts = ReviewTask::query()->open()
            ->selectRaw('kind, count(*) as total')
            ->groupBy('kind')
            ->pluck('total', 'kind');

        return Inertia::render('review/Index', [
            'tasks' => $tasks,
            'kinds' => array_map(fn (TaskKind $k) => [
                'value' => $k->value,
                'label' => $k->label(),
                'open' => (int) ($counts[$k->value] ?? 0),
            ], $kinds),
            'filters' => ['kind' => $kind?->value],
            'exposure' => [
                'graphic_today' => $this->moderation->graphicExposure($user),
                'limit' => config('moderation.graphic_daily_limit'),
            ],
        ]);
    }

    /**
     * Claim the most urgent task the user may take and open it.
     */
    public function next(Request $request): RedirectResponse
    {
        Gate::authorize('moderate');

        $task = $this->moderation->claimNext($request->user(), TaskKind::tryFrom((string) $request->input('kind')));

        if ($task === null) {
            Inertia::flash('toast', ['type' => 'info', 'message' => 'Nothing left in the queue for you. Nice work.']);

            return to_route('review.index');
        }

        return to_route('review.show', $task);
    }

    public function show(Request $request, ReviewTask $task): Response
    {
        Gate::authorize('moderate');

        $user = $request->user();

        // Escalations are for admins only, even read-only: the note is addressed to them.
        abort_unless(in_array($task->kind, $this->moderation->kindsFor($user), true), 403);

        $task->load(['submission.steps', 'submission.tasks.claimant', 'submission.tasks.resolver', 'submission.user', 'submission.decider', 'submission.policyVersion', 'claimant']);

        $submission = SubmissionPresenter::detail($task->submission, $user);

        // A second review is blind: while it is open, the earlier decision stays out of the page.
        if ($task->kind === TaskKind::SecondReview && $task->isOpen()) {
            $submission = [
                ...$submission,
                'status' => 'pending_review',
                'decision_source' => null,
                'decision_reason' => null,
                'decided_by' => null,
                'decided_at' => null,
                'appeal_deadline' => null,
                'tasks' => [],
            ];
        }

        return Inertia::render('review/Show', [
            'task' => [
                ...SubmissionPresenter::task($task),
                'open' => $task->isOpen(),
                'mine' => $task->isClaimedBy($user),
                'can_claim' => $task->isOpen() && ! $task->hasLiveClaim() && $this->moderation->mayWork($user, $task),
                'may_work' => $this->moderation->mayWork($user, $task),
                'excluded' => $task->excluded_reviewer_id === $user->id,
            ],
            'submission' => $submission,
            'reasons' => [
                ...array_map(fn (Category $c) => ['value' => $c->value, 'label' => $c->label()], Category::cases()),
                ['value' => 'other', 'label' => 'Other policy violation'],
            ],
            'claimMinutes' => config('moderation.claim_minutes'),
        ]);
    }

    public function claim(Request $request, ReviewTask $task): RedirectResponse
    {
        Gate::authorize('moderate');

        if (! $this->moderation->claim($task, $request->user())) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Someone else is reviewing this item, or you can’t take it.']);
        }

        return to_route('review.show', $task);
    }

    public function release(Request $request, ReviewTask $task): RedirectResponse
    {
        Gate::authorize('moderate');

        $this->moderation->release($task, $request->user());

        return to_route('review.index');
    }

    public function decide(Request $request, ReviewTask $task): RedirectResponse
    {
        Gate::authorize('moderate');

        $validated = $request->validate([
            'decision' => ['required', Rule::enum(Decision::class)],
            'reason' => [
                Rule::requiredIf($request->input('decision') === Decision::Reject->value),
                'nullable',
                Rule::in([...array_map(fn (Category $c) => $c->value, Category::cases()), 'other']),
            ],
            'note' => [
                Rule::requiredIf($request->input('decision') === Decision::Escalate->value),
                'nullable', 'string', 'max:1000',
            ],
            'block_image' => ['boolean'],
            'next' => ['boolean'],
        ], [
            'reason.required' => 'Pick the policy the content breaks.',
            'note.required' => 'Say why you are escalating, for the admin who picks it up.',
        ]);

        $decision = Decision::from($validated['decision']);
        $user = $request->user();

        if (! $task->isClaimedBy($user)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Your claim on this task expired or was taken. Claim it again to decide.']);

            return to_route('review.show', $task);
        }

        if ($decision === Decision::Escalate && ! $task->kind->canEscalate()) {
            return back()->withErrors(['decision' => $task->kind === TaskKind::Escalation
                ? 'An escalation is already with an admin.'
                : 'A second review is approve or reject only; disagreements go to an admin automatically.']);
        }

        try {
            $this->moderation->decide(
                $task,
                $user,
                $decision,
                $validated['reason'] ?? null,
                $validated['note'] ?? null,
                // Audits and second reviews measure decisions; blocking re-uploads is a separate, deliberate choice.
                $validated['block_image'] ?? ! in_array($task->kind, [TaskKind::Audit, TaskKind::SecondReview], true),
            );
        } catch (ClaimLostException) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Your claim on this task expired or was taken. Claim it again to decide.']);

            return to_route('review.show', $task);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($decision) {
            Decision::Approve => 'Approved.',
            Decision::Reject => 'Rejected.',
            Decision::Escalate => 'Escalated to an admin.',
        }]);

        if ($validated['next'] ?? false) {
            $next = $this->moderation->claimNext($user);

            if ($next) {
                return to_route('review.show', $next);
            }
        }

        return to_route('review.index');
    }
}
