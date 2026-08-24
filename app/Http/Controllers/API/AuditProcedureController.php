<?php

namespace App\Http\Controllers\API;

use App\Access\FileAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExecuteAuditProcedureRequest;
use App\Http\Requests\ListAuditProceduresRequest;
use App\Http\Requests\ReviewAuditWorkpaperRequest;
use App\Http\Requests\StoreAuditProcedureRequest;
use App\Models\Audit;
use App\Models\AuditProcedure;
use App\Models\AuditProcedureExecution;
use App\Services\AuditProcedureManager;
use Illuminate\Http\JsonResponse;

class AuditProcedureController extends Controller
{
    public function index(ListAuditProceduresRequest $request, Audit $audit, FileAccess $files): JsonResponse
    {
        $this->authorize('view', $audit);
        $procedures = $audit->procedures()->with([
            'auditItem.auditable', 'assignee:id,name', 'creator:id,name', 'execution.executor:id,name', 'execution.review.reviewer:id,name',
            'execution.evidence.attachment.audit.members', 'execution.evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25));
        $procedures->getCollection()->each(function (AuditProcedure $procedure) use ($request, $files): void {
            $execution = $procedure->execution;
            if (! $execution) {
                return;
            }
            $authorizedEvidence = $execution->evidence->filter(fn ($evidence): bool => $evidence->attachment
                && $files->canDownloadFileAttachment($request->user(), $evidence->attachment))->values();
            $authorizedIds = $authorizedEvidence->pluck('file_attachment_id');
            $authorizedEvidence->each(fn ($evidence) => $evidence->unsetRelation('attachment'));
            $execution->setRelation('evidence', $authorizedEvidence);
            $execution->setAttribute('evidence_manifest', collect($execution->evidence_manifest)->whereIn('file_attachment_id', $authorizedIds)->values()->all());
            if ($execution->review) {
                $reviewSnapshot = $execution->review->execution_snapshot;
                $reviewSnapshot['evidence_manifest'] = collect($reviewSnapshot['evidence_manifest'] ?? [])->whereIn('file_attachment_id', $authorizedIds)->values()->all();
                $execution->review->setAttribute('execution_snapshot', $reviewSnapshot);
            }
        });

        return response()->json($procedures);
    }

    public function store(StoreAuditProcedureRequest $request, Audit $audit, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->define($audit, $request->user(), $request->validated())], 201);
    }

    public function execute(ExecuteAuditProcedureRequest $request, AuditProcedure $procedure, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->execute($procedure, $request->user(), $request->validated())], 201);
    }

    public function review(ReviewAuditWorkpaperRequest $request, AuditProcedureExecution $execution, AuditProcedureManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($execution, $request->user(), $request->validated())], 201);
    }
}
