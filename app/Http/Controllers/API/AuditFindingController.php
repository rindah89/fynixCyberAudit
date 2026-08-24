<?php

namespace App\Http\Controllers\API;

use App\Access\FileAccess;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditFindingsRequest;
use App\Http\Requests\ShowAuditFindingRequest;
use App\Http\Requests\StoreAuditFindingFollowUpRequest;
use App\Http\Requests\StoreAuditFindingRemediationRequest;
use App\Http\Requests\StoreAuditFindingRequest;
use App\Http\Requests\StoreAuditManagementResponseRequest;
use App\Models\Audit;
use App\Models\AuditFinding;
use App\Models\AuditFindingRemediation;
use App\Models\RemediationProject;
use App\Services\AuditFindingManager;
use App\Services\AuditFindingRemediationManager;
use Illuminate\Http\JsonResponse;

class AuditFindingController extends Controller
{
    public function index(ListAuditFindingsRequest $request, Audit $audit): JsonResponse
    {
        return response()->json($audit->governedFindings()->with(['auditItem.auditable', 'accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name'])
            ->orderByDesc('id')->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreAuditFindingRequest $request, Audit $audit, AuditFindingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->raise($audit, $request->user(), $request->validated())], 201);
    }

    public function show(ShowAuditFindingRequest $request, AuditFinding $finding, FileAccess $files): JsonResponse
    {
        $finding->load([
            'accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name', 'remediation.task', 'remediation.handoffActor:id,name',
            'remediation.followUps.reviewer:id,name', 'remediation.followUps.evidence.attachment.audit.members',
            'remediation.followUps.evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]);
        foreach ($finding->remediation?->followUps ?? [] as $followUp) {
            $authorizedEvidence = $followUp->evidence->filter(fn ($evidence): bool => $evidence->attachment && $files->canDownloadFileAttachment($request->user(), $evidence->attachment))->values();
            $authorizedIds = $authorizedEvidence->pluck('file_attachment_id');
            $authorizedEvidence->each(fn ($evidence) => $evidence->unsetRelation('attachment'));
            $followUp->setRelation('evidence', $authorizedEvidence);
            $followUp->setAttribute('evidence_manifest', collect($followUp->evidence_manifest)->whereIn('file_attachment_id', $authorizedIds)->values()->all());
        }

        return response()->json(['data' => $finding]);
    }

    public function respond(StoreAuditManagementResponseRequest $request, AuditFinding $finding, AuditFindingManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->respond($finding, $request->user(), $request->validated())], 201);
    }

    public function handoff(StoreAuditFindingRemediationRequest $request, AuditFinding $finding, AuditFindingRemediationManager $manager): JsonResponse
    {
        $project = RemediationProject::query()->findOrFail($request->integer('remediation_project_id'));

        return response()->json(['data' => $manager->handoff($finding, $request->user(), $project, $request->safe()->except('remediation_project_id'))], 201);
    }

    public function followUp(StoreAuditFindingFollowUpRequest $request, AuditFindingRemediation $remediation, AuditFindingRemediationManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->followUp($remediation, $request->user(), $request->validated())], 201);
    }
}
