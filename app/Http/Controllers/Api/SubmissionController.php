<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSubmissionRequest;
use App\Http\Resources\SubmissionResource;
use App\Models\Submission;
use App\Moderation\SubmissionIntake;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class SubmissionController extends Controller
{
    /**
     * The token owner's submissions, newest first.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['status' => ['nullable', 'in:processing,pending_review,approved,rejected']]);

        return SubmissionResource::collection(
            $request->user()->submissions()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')
                ->paginate(50)
                ->withQueryString(),
        );
    }

    /**
     * Accept an upload. The answer comes back 202 while the pipeline runs; poll the
     * submission or wait for the submission.decided webhook. Sending a reference
     * already used returns the original submission, so retries are safe.
     */
    public function store(StoreSubmissionRequest $request, SubmissionIntake $intake): JsonResponse
    {
        $reference = $request->validated('reference');

        if (filled($reference)) {
            $existing = $request->user()->submissions()->where('external_ref', trim($reference))->first();

            if ($existing !== null) {
                return (new SubmissionResource($existing))->response()->setStatusCode(200);
            }
        }

        try {
            $submission = $intake->accept($request->user(), $request->validated('caption'), $request->file('image'), $reference);
        } catch (UniqueConstraintViolationException) {
            // A concurrent retry with the same reference got there first.
            return (new SubmissionResource($request->user()->submissions()->where('external_ref', trim((string) $reference))->firstOrFail()))->response();
        }

        return (new SubmissionResource($submission->refresh()))
            ->response()
            ->setStatusCode(202)
            ->header('Location', route('api.submissions.show', $submission));
    }

    public function show(Submission $submission): SubmissionResource
    {
        Gate::authorize('view', $submission);

        return new SubmissionResource($submission);
    }
}
