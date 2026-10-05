<?php

namespace App\Http\Controllers;

use App\Enums\Category;
use App\Models\BlockedHash;
use App\Models\PolicyVersion;
use App\Moderation\PolicyBacktest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;
use Inertia\Inertia;
use Inertia\Response;

class PolicyController extends Controller
{
    /**
     * Staff can read the policy; only admins can change it.
     */
    public function edit(Request $request): Response
    {
        Gate::authorize('moderate');

        $current = PolicyVersion::current();

        return Inertia::render('policy/Edit', [
            'policy' => [
                'id' => $current->id,
                'audit_sample_rate' => $current->audit_sample_rate,
                'blocklist_distance' => $current->blocklist_distance,
                'categories' => array_map(fn (Category $c) => [
                    'value' => $c->value,
                    'label' => $c->label(),
                    'definition' => $c->definition(),
                    'severity' => $c->severity(),
                    ...$current->thresholdsFor($c),
                ], Category::cases()),
            ],
            'versions' => PolicyVersion::query()->with('author:id,name')->latest('id')->limit(10)->get()
                ->map(fn (PolicyVersion $v) => [
                    'id' => $v->id,
                    'note' => $v->note,
                    'author' => $v->author?->name,
                    'created_at' => $v->created_at->toIso8601String(),
                ]),
            'blocklistSize' => BlockedHash::query()->count(),
            'canEdit' => $request->user()->can('manage-policy'),
        ]);
    }

    /**
     * Backtest proposed thresholds against recent classified items.
     */
    public function preview(Request $request, PolicyBacktest $backtest): JsonResponse
    {
        Gate::authorize('manage-policy');

        $thresholds = $this->validatedThresholds($request);

        return response()->json($backtest->run(PolicyVersion::current()->thresholds, $thresholds));
    }

    /**
     * Save a new policy version. Existing decisions keep pointing at the version they used.
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('manage-policy');

        $thresholds = $this->validatedThresholds($request);
        $validated = $request->validate([
            'audit_sample_rate' => ['required', 'numeric', 'between:0,1'],
            'blocklist_distance' => ['required', 'integer', 'between:0,16'],
            'note' => ['required', 'string', 'max:255'],
        ], [
            'note.required' => 'Describe the change so the history makes sense later.',
        ]);

        PolicyVersion::create([
            'thresholds' => $thresholds,
            'audit_sample_rate' => round((float) $validated['audit_sample_rate'], 3),
            'blocklist_distance' => (int) $validated['blocklist_distance'],
            'note' => $validated['note'],
            'created_by' => $request->user()->id,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Policy saved. New submissions use it from now on.']);

        return to_route('policy.edit');
    }

    /**
     * @return array<string, array{review: float, reject: float|null}>
     */
    private function validatedThresholds(Request $request): array
    {
        $rules = [];

        foreach (Category::cases() as $category) {
            $rules["thresholds.{$category->value}.review"] = ['required', 'numeric', 'between:0.05,1'];
            $rules["thresholds.{$category->value}.reject"] = ['nullable', 'numeric', 'between:0.05,1'];
        }

        $validated = $request->validate($rules, [], [
            'thresholds.*.review' => 'review threshold',
            'thresholds.*.reject' => 'reject threshold',
        ]);

        validator($validated)->after(function (Validator $validator) use ($validated) {
            foreach ($validated['thresholds'] as $category => $limits) {
                if (isset($limits['reject']) && $limits['reject'] !== null && (float) $limits['reject'] < (float) $limits['review']) {
                    $validator->errors()->add("thresholds.{$category}.reject", 'The reject threshold must be at or above the review threshold.');
                }
            }
        })->validate();

        $thresholds = [];

        foreach (Category::cases() as $category) {
            $limits = $validated['thresholds'][$category->value];
            $thresholds[$category->value] = [
                'review' => round((float) $limits['review'], 3),
                'reject' => isset($limits['reject']) && $limits['reject'] !== null ? round((float) $limits['reject'], 3) : null,
            ];
        }

        return $thresholds;
    }
}
