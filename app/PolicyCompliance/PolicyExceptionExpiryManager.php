<?php

namespace App\PolicyCompliance;

use App\Enums\PolicyExceptionStatus;
use App\Models\Policy;
use App\Models\PolicyException;
use App\Models\PolicyExceptionDecision;
use App\Models\PolicyExceptionMonitoringReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyExceptionExpiryManager
{
    public function reconcile(?Carbon $asOf = null): int
    {
        $asOf ??= now();
        $reconciliationId = Str::uuid()->toString();
        $expired = 0;

        PolicyException::query()
            ->whereNotNull('governance_fingerprint')
            ->where('status', PolicyExceptionStatus::Approved)
            ->whereNotNull('expiration_date')
            ->whereDate('expiration_date', '<', $asOf->toDateString())
            ->orderBy('id')
            ->pluck('id')
            ->each(function (int $exceptionId) use ($asOf, $reconciliationId, &$expired): void {
                if ($this->expire($exceptionId, $asOf, $reconciliationId)) {
                    $expired++;
                }
            });

        return $expired;
    }

    private function expire(int $exceptionId, Carbon $asOf, string $reconciliationId): bool
    {
        return DB::transaction(function () use ($exceptionId, $asOf, $reconciliationId): bool {
            $policyId = PolicyException::withTrashed()->whereKey($exceptionId)->value('policy_id');
            if (! $policyId) {
                return false;
            }
            $policy = Policy::withTrashed()->lockForUpdate()->findOrFail($policyId);
            $exception = PolicyException::withTrashed()->lockForUpdate()->findOrFail($exceptionId);
            if (! $exception->governance_fingerprint
                || $exception->status !== PolicyExceptionStatus::Approved
                || ! $exception->expiration_date
                || $exception->expiration_date->greaterThanOrEqualTo($asOf->copy()->startOfDay())
                || $exception->expiration()->exists()) {
                return false;
            }

            $decision = PolicyExceptionDecision::query()
                ->where('policy_exception_id', $exception->id)->latest('version')->lockForUpdate()->firstOrFail();
            $currentPolicyContext = app(PolicyExceptionGovernanceManager::class)->currentPolicyContext($policy, true);
            $approvedPolicyContext = collect($exception->governance_snapshot)
                ->only(['policy', 'approved_revision', 'revision_governance_status', 'deleted_at'])->all();
            $governanceSnapshot = PolicyExceptionMonitoringManager::snapshot(
                $exception,
                $decision,
                $approvedPolicyContext,
                $currentPolicyContext,
                $currentPolicyContext === $approvedPolicyContext,
            );
            $latestReview = PolicyExceptionMonitoringReview::query()
                ->where('policy_exception_id', $exception->id)
                ->latest('version')->lockForUpdate()->first();
            $latestReviewSnapshot = $latestReview ? $this->reviewSnapshot($latestReview) : null;
            $snapshot = [
                'exception' => $governanceSnapshot,
                'latest_monitoring_review' => $latestReviewSnapshot,
            ];
            $expiredAt = $exception->expiration_date->copy()->addDay()->startOfDay();
            $reconciledAt = $asOf->copy()->startOfSecond();
            $payload = [
                'policy_exception_id' => $exception->id,
                'prior_status' => $exception->status->value,
                'expiration_date' => $exception->expiration_date->toDateString(),
                'expired_at' => $expiredAt->toISOString(),
                'reconciled_at' => $reconciledAt->toISOString(),
                'reconciliation_id' => $reconciliationId,
                'source' => 'server_reconciliation',
                'exception_snapshot' => $snapshot,
            ];
            $exception->expiration()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ]);
            $exception->update(['status' => PolicyExceptionStatus::Expired]);

            return true;
        }, 3);
    }

    private function reviewSnapshot(PolicyExceptionMonitoringReview $review): array
    {
        return [
            'id' => $review->id,
            'version' => $review->version,
            'outcome' => $review->outcome->value,
            'review_summary' => $review->review_summary,
            'control_effectiveness' => $review->control_effectiveness,
            'evidence_reference' => $review->evidence_reference,
            'exception_snapshot' => $review->exception_snapshot,
            'reviewed_by' => $review->reviewed_by,
            'reviewed_at' => $review->reviewed_at?->toISOString(),
            'next_review_at' => $review->next_review_at?->toISOString(),
            'fingerprint' => $review->fingerprint,
        ];
    }
}
