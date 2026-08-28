<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\DispositionReceipt;
use App\Models\GovernanceException;
use App\Models\GovernanceStatement;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\RecoveryEvidence;
use Carbon\CarbonImmutable;

class GovernanceOversightService
{
    public function __construct(
        private readonly DataGovernanceControlService $controls,
        private readonly GovernanceReviewIntegrityService $reviewIntegrity,
    ) {}

    /** @return array<string, mixed> */
    public function report(bool $includeDetails = true): array
    {
        $now = CarbonImmutable::now('UTC');
        $freshAfter = $now->subHours((int) config('data_governance.freshness_hours', 26));
        $sources = [];
        foreach (config('data_governance.required_sources', []) as $source) {
            $binding = config('data_governance.bindings.'.$source);
            $latest = GovernanceStatement::query()->with('controlResults')->where('source', $source)->latest('occurred_at')->first();
            $bindingEnabled = is_array($binding)
                && ($binding['enabled'] ?? false)
                && ! empty($binding['tenant_id'])
                && ! empty($binding['webhook_id'])
                && ! empty($binding['secret']);
            $tenantId = is_array($binding) ? (string) ($binding['tenant_id'] ?? '') : '';
            $overduePrivacy = PrivacyRequest::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'open'])->where('due_at', '<', $now)->count();
            $pendingProcessors = DataProcessor::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'pending_review', 'active' => true])->count();
            $pendingPrivacy = PrivacyRequest::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'closed', 'review_status' => 'pending_review'])->count();
            $pendingRecovery = RecoveryEvidence::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'review_status' => 'pending_review'])->count();
            $activeHolds = LegalHold::query()->whereNull('released_at')->whereHas('retentionPolicy', fn ($query) => $query->where(['tenant_id' => $tenantId, 'source' => $source]))->count();
            $dispositionReceipts = DispositionReceipt::query()->where(['tenant_id' => $tenantId, 'source' => $source])->count();
            $pendingDispositions = DispositionReceipt::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'review_status' => 'pending_review'])->count();
            $invalidReviews = DataProcessor::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'approved', 'active' => true])->get()
                ->reject(fn (DataProcessor $resource): bool => $this->reviewIntegrity->approved($resource, 'processor'))->count();
            $invalidReviews += RecoveryEvidence::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'review_status' => 'approved'])->get()
                ->reject(fn (RecoveryEvidence $resource): bool => $this->reviewIntegrity->approved($resource, 'recovery_evidence'))->count();
            $invalidReviews += PrivacyRequest::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'status' => 'closed', 'review_status' => 'approved'])->get()
                ->reject(fn (PrivacyRequest $resource): bool => $this->reviewIntegrity->approved($resource, 'privacy_completion'))->count();
            $invalidReviews += DispositionReceipt::query()->where(['tenant_id' => $tenantId, 'source' => $source, 'review_status' => 'approved'])->get()
                ->reject(fn (DispositionReceipt $resource): bool => $this->reviewIntegrity->approved($resource, 'disposition_receipt'))->count();
            $sources[$source] = [
                'binding' => $bindingEnabled ? 'enabled' : 'missing',
                'freshness' => ! $latest ? 'missing' : ($latest->occurred_at->lessThan($freshAfter) ? 'stale' : 'current'),
                'last_statement_at' => $latest?->occurred_at?->toIso8601String(),
                'effective_controls' => $latest?->controlResults->whereIn('status', ['effective', 'not_applicable'])->count() ?? 0,
                'total_controls' => count(config('data_governance.controls', [])),
                'open_exceptions' => GovernanceException::query()->where('source', $source)->where('status', 'open')->count(),
                'waived_exceptions' => GovernanceException::query()->where('source', $source)->where('status', 'waived')->count(),
                'operability' => [
                    'overdue_privacy_requests' => $overduePrivacy,
                    'active_legal_holds' => $activeHolds,
                    'pending_processor_reviews' => $pendingProcessors,
                    'pending_privacy_reviews' => $pendingPrivacy,
                    'pending_recovery_reviews' => $pendingRecovery,
                    'pending_disposition_reviews' => $pendingDispositions,
                    'invalid_or_tampered_reviews' => $invalidReviews,
                    'disposition_receipts' => $dispositionReceipts,
                    'current_approved_restore_evidence' => $tenantId !== '' && $this->controls->hasCurrentRecoveryEvidence($tenantId, $source, $now),
                    'processor_register_certified' => $tenantId !== '' && $this->controls->hasCurrentProcessorRegister($tenantId, $source, $now),
                ],
            ];
        }
        $processorInventoryCurrent = $this->controls->hasCurrentProcessorInventoryRun($now);
        $ready = $processorInventoryCurrent && collect($sources)->every(fn (array $source): bool => $source['binding'] === 'enabled' && $source['freshness'] === 'current' && $source['open_exceptions'] === 0 && $source['operability']['overdue_privacy_requests'] === 0 && $source['operability']['pending_processor_reviews'] === 0 && $source['operability']['pending_privacy_reviews'] === 0 && $source['operability']['pending_recovery_reviews'] === 0 && $source['operability']['pending_disposition_reviews'] === 0 && $source['operability']['invalid_or_tampered_reviews'] === 0);
        $hasWaivers = collect($sources)->contains(fn (array $source): bool => $source['waived_exceptions'] > 0);
        $report = ['status' => $ready ? ($hasWaivers ? 'conformant_with_waivers' : 'conformant') : 'attention_required', 'generated_at' => $now->toIso8601String(), 'processor_inventory_reconciliation' => $processorInventoryCurrent ? 'current' : 'missing_failed_or_stale', 'sources' => $sources];
        if ($includeDetails) {
            $report['controls'] = config('data_governance.controls');
            $report['open_exceptions'] = GovernanceException::query()->whereIn('status', ['open', 'waived'])->orderByRaw("CASE severity WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")->orderBy('due_at')->get();
        }

        return $report;
    }
}
