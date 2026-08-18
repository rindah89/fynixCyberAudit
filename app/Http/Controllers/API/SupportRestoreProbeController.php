<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SupportRestoreProbeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()?->tokenCan('support:restore-probe'), 403);
        $this->authorize('viewAny', Audit::class);

        $validated = $request->validate([
            'operation_id' => ['required', 'uuid'],
        ]);
        $path = 'support-restore-probes/'.Str::lower($validated['operation_id']).'.probe';
        $payload = hash('sha256', $validated['operation_id'], true);
        $disk = Storage::disk('private');

        try {
            abort_unless($disk->put($path, $payload), 503, 'Evidence storage write failed.');
            abort_unless(hash_equals($payload, (string) $disk->get($path)), 503, 'Evidence storage read failed.');
            abort_unless($disk->delete($path) && ! $disk->exists($path), 503, 'Evidence storage cleanup failed.');
        } finally {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
        }

        return response()->json([
            'operation_id' => $validated['operation_id'],
            'authenticated' => true,
            'audit_records' => Audit::query()->count(),
            'evidence_storage' => 'read-write',
            'probe_cleanup' => true,
        ]);
    }
}
