<?php

namespace App\Http\Controllers\API;

use App\Access\FileAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditCloseoutsRequest;
use App\Http\Requests\ReviewAuditCloseoutRequest;
use App\Http\Requests\SubmitAuditCloseoutRequest;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Models\FileAttachment;
use App\Models\User;
use App\Services\AuditCloseoutManager;
use Illuminate\Http\JsonResponse;

class AuditCloseoutController extends Controller
{
    public function index(ListAuditCloseoutsRequest $request, Audit $audit, FileAccess $files): JsonResponse
    {
        $submissions = $audit->closeoutSubmissions()->with(['submitter:id,name', 'review.reviewer:id,name'])
            ->latest('version')->paginate($request->integer('per_page', 50));
        $submissions->getCollection()->each(fn (AuditCloseoutSubmission $submission) => $this->redactProcedureEvidence($submission, $request->user(), $files));

        return response()->json($submissions);
    }

    public function submit(SubmitAuditCloseoutRequest $request, Audit $audit, AuditCloseoutManager $manager, FileAccess $files): JsonResponse
    {
        $submission = $manager->submit($audit, $request->user(), $request->validated());
        $this->redactProcedureEvidence($submission, $request->user(), $files);

        return response()->json(['data' => $submission], JsonResponse::HTTP_CREATED);
    }

    public function review(ReviewAuditCloseoutRequest $request, AuditCloseoutSubmission $submission, AuditCloseoutManager $manager, FileAccess $files): JsonResponse
    {
        $review = $manager->review($submission, $request->user(), $request->validated());
        $snapshot = $review->report_snapshot;
        $snapshot['audit_procedure_snapshots'] = $this->redactedProcedureSnapshots($snapshot['audit_procedure_snapshots'] ?? [], $request->user(), $files);
        $review->setAttribute('report_snapshot', $snapshot);
        if ($review->submission) {
            $this->redactProcedureEvidence($review->submission, $request->user(), $files);
        }

        return response()->json(['data' => $review], JsonResponse::HTTP_CREATED);
    }

    private function redactProcedureEvidence(AuditCloseoutSubmission $submission, User $actor, FileAccess $files): void
    {
        $submission->setAttribute('audit_procedure_snapshots', $this->redactedProcedureSnapshots($submission->audit_procedure_snapshots ?? [], $actor, $files));
        if ($submission->review) {
            $snapshot = $submission->review->report_snapshot;
            $snapshot['audit_procedure_snapshots'] = $this->redactedProcedureSnapshots($snapshot['audit_procedure_snapshots'] ?? [], $actor, $files);
            $submission->review->setAttribute('report_snapshot', $snapshot);
        }
    }

    private function redactedProcedureSnapshots(array $snapshots, User $actor, FileAccess $files): array
    {
        $attachmentIds = collect($snapshots)->flatMap(fn (array $procedure) => collect(data_get($procedure, 'execution.evidence_manifest', []))->pluck('file_attachment_id'))->unique()->values();
        $attachments = FileAttachment::query()->whereKey($attachmentIds)->with([
            'audit.members', 'dataRequestResponse.dataRequest.audit.members',
        ])->get()->keyBy('id');
        $authorizedIds = $attachments->filter(fn (FileAttachment $attachment): bool => $files->canDownloadFileAttachment($actor, $attachment))->keys();

        return collect($snapshots)->map(function (array $procedure) use ($authorizedIds): array {
            data_set($procedure, 'execution.evidence_manifest', collect(data_get($procedure, 'execution.evidence_manifest', []))->whereIn('file_attachment_id', $authorizedIds)->values()->all());
            data_set($procedure, 'supervisory_review.execution_snapshot.evidence_manifest', collect(data_get($procedure, 'supervisory_review.execution_snapshot.evidence_manifest', []))->whereIn('file_attachment_id', $authorizedIds)->values()->all());

            return $procedure;
        })->all();
    }
}
