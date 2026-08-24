<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\ListAuditCloseoutsRequest;
use App\Http\Requests\ReviewAuditCloseoutRequest;
use App\Http\Requests\SubmitAuditCloseoutRequest;
use App\Models\Audit;
use App\Models\AuditCloseoutSubmission;
use App\Services\AuditCloseoutManager;
use Illuminate\Http\JsonResponse;

class AuditCloseoutController extends Controller
{
    public function index(ListAuditCloseoutsRequest $request, Audit $audit): JsonResponse
    {
        return response()->json($audit->closeoutSubmissions()->with(['submitter:id,name', 'review.reviewer:id,name'])
            ->latest('version')->paginate($request->integer('per_page', 50)));
    }

    public function submit(SubmitAuditCloseoutRequest $request, Audit $audit, AuditCloseoutManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->submit($audit, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }

    public function review(ReviewAuditCloseoutRequest $request, AuditCloseoutSubmission $submission, AuditCloseoutManager $manager): JsonResponse
    {
        return response()->json(['data' => $manager->review($submission, $request->user(), $request->validated())], JsonResponse::HTTP_CREATED);
    }
}
