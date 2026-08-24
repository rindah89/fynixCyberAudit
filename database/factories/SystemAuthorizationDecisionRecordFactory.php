<?php

namespace Database\Factories;

use App\Enums\SystemAuthorizationDecision;
use App\Models\SystemAuthorizationDecisionRecord;
use App\Models\SystemAuthorizationPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

class SystemAuthorizationDecisionRecordFactory extends Factory
{
    protected $model = SystemAuthorizationDecisionRecord::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['system_authorization_package_id' => SystemAuthorizationPackage::factory(), 'version' => 1, 'package_snapshot' => fn (array $a): array => self::packageSnapshot(SystemAuthorizationPackage::findOrFail($a['system_authorization_package_id'])), 'decision' => SystemAuthorizationDecision::Authorized, 'conditions' => [], 'rationale' => 'Factory independent authorization decision.', 'decided_by' => User::factory(), 'decided_at' => $at, 'valid_until' => today()->addYear(), 'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['system_authorization_package_id' => $a['system_authorization_package_id'], 'version' => $a['version'], 'package_snapshot' => $a['package_snapshot'], 'decision' => $a['decision'] instanceof SystemAuthorizationDecision ? $a['decision']->value : $a['decision'], 'conditions' => $a['conditions'], 'rationale' => $a['rationale'], 'decided_by' => $a['decided_by'], 'decided_at' => $at->toIso8601String(), 'valid_until' => Carbon::parse($a['valid_until'])->toDateString()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public static function packageSnapshot(SystemAuthorizationPackage $package): array
    {
        return json_decode(json_encode($package->only(['id', 'application_id', 'version', 'application_snapshot', 'system_boundary', 'impact_level', 'data_classifications', 'control_snapshot', 'risk_snapshot', 'open_findings', 'monitoring_strategy', 'review_frequency_days', 'poam_reference', 'change_summary', 'submitted_by', 'submitted_at', 'fingerprint']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
