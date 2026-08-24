<?php

namespace Database\Factories;

use App\Enums\SystemAuthorizationMonitoringOutcome;
use App\Models\SystemAuthorizationDecisionRecord;
use App\Models\SystemAuthorizationMonitoringReview;
use App\Models\SystemAuthorizationPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemAuthorizationMonitoringReviewFactory extends Factory
{
    protected $model = SystemAuthorizationMonitoringReview::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['system_authorization_package_id' => SystemAuthorizationPackage::factory(), 'version' => 1, 'package_snapshot' => fn (array $a): array => SystemAuthorizationDecisionRecordFactory::packageSnapshot(SystemAuthorizationPackage::findOrFail($a['system_authorization_package_id'])), 'decision_snapshot' => fn (array $a): array => self::decisionSnapshot(SystemAuthorizationDecisionRecord::factory()->create(['system_authorization_package_id' => $a['system_authorization_package_id']])), 'metrics' => ['Factory metric reviewed'], 'findings' => [], 'outcome' => SystemAuthorizationMonitoringOutcome::Effective, 'required_actions' => [], 'summary' => 'Factory monitoring review.', 'reviewed_by' => User::factory(), 'reviewed_at' => $at, 'next_review_at' => today()->addDays(90), 'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['system_authorization_package_id' => $a['system_authorization_package_id'], 'version' => $a['version'], 'package_snapshot' => $a['package_snapshot'], 'decision_snapshot' => $a['decision_snapshot'], 'metrics' => $a['metrics'], 'findings' => $a['findings'], 'outcome' => $a['outcome'] instanceof SystemAuthorizationMonitoringOutcome ? $a['outcome']->value : $a['outcome'], 'required_actions' => $a['required_actions'], 'summary' => $a['summary'], 'reviewed_by' => $a['reviewed_by'], 'reviewed_at' => $at->toIso8601String(), 'next_review_at' => today()->addDays(90)->toDateString()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public static function decisionSnapshot(SystemAuthorizationDecisionRecord $decision): array
    {
        return json_decode(json_encode($decision->only(['id', 'system_authorization_package_id', 'version', 'package_snapshot', 'decision', 'conditions', 'rationale', 'decided_by', 'decided_at', 'valid_until', 'fingerprint']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
