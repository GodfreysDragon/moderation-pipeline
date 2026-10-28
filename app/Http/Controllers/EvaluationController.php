<?php

namespace App\Http\Controllers;

use App\Enums\EvaluationStatus;
use App\Jobs\StartEvaluation;
use App\Models\EvaluationRun;
use App\Models\PolicyVersion;
use App\Moderation\EvaluationReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Re-score human-decided items with another model or effort level, in a batch,
 * before switching the pipeline over.
 */
class EvaluationController extends Controller
{
    private const EFFORTS = ['low', 'medium', 'high'];

    public function index(): Response
    {
        Gate::authorize('manage-policy');

        return Inertia::render('evaluations/Index', [
            'runs' => EvaluationRun::query()
                ->with('creator:id,name')
                ->withCount(['results', 'results as scored_count' => fn ($q) => $q->whereNotNull('scores')])
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (EvaluationRun $run) => $this->summary($run)),
            'models' => array_keys(config('moderation.pricing')),
            'efforts' => self::EFFORTS,
            'current' => ['model' => config('moderation.anthropic.model'), 'effort' => 'low'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-policy');

        $validated = $request->validate([
            'model' => ['required', Rule::in(array_keys(config('moderation.pricing')))],
            'effort' => ['required', Rule::in(self::EFFORTS)],
            'sample_size' => ['required', 'integer', 'min:10', 'max:200'],
        ]);

        $run = EvaluationRun::create([
            ...$validated,
            'policy_version_id' => PolicyVersion::current()->id,
            'created_by' => $request->user()->id,
        ]);

        StartEvaluation::dispatch($run);

        return to_route('evaluations.show', $run);
    }

    public function show(EvaluationRun $evaluation, EvaluationReport $report): Response
    {
        Gate::authorize('manage-policy');

        $evaluation->load('creator:id,name', 'policyVersion')->loadCount(['results', 'results as scored_count' => fn ($q) => $q->whereNotNull('scores')]);

        return Inertia::render('evaluations/Show', [
            'run' => $this->summary($evaluation),
            'report' => $evaluation->status === EvaluationStatus::Completed ? $report->build($evaluation) : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(EvaluationRun $run): array
    {
        return [
            'id' => $run->id,
            'model' => $run->model,
            'effort' => $run->effort,
            'sample_size' => $run->sample_size,
            'status' => $run->status->value,
            'error' => $run->error,
            'items' => (int) $run->getAttribute('results_count'),
            'scored' => (int) $run->getAttribute('scored_count'),
            'policy_version' => $run->policy_version_id,
            'created_by' => $run->creator?->name,
            'created_at' => $run->created_at->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
        ];
    }
}
