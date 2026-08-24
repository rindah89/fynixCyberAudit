<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\SystemAuthorizationPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SystemAuthorizationPackageFactory extends Factory
{
    protected $model = SystemAuthorizationPackage::class;

    public function definition(): array
    {
        $at = now()->startOfSecond();

        return ['application_id' => Application::factory(), 'version' => 1, 'application_snapshot' => fn (array $a): array => self::applicationSnapshot(Application::findOrFail($a['application_id'])), 'system_boundary' => 'Factory authorization boundary.', 'impact_level' => 'Moderate', 'data_classifications' => ['Confidential'], 'control_snapshot' => [], 'risk_snapshot' => [], 'open_findings' => [], 'monitoring_strategy' => 'Quarterly deliberate review.', 'review_frequency_days' => 90, 'poam_reference' => null, 'change_summary' => 'Factory authorization package.', 'submitted_by' => User::factory(), 'submitted_at' => $at, 'fingerprint' => fn (array $a): string => hash('sha256', json_encode(['application_id' => $a['application_id'], 'version' => $a['version'], 'application_snapshot' => $a['application_snapshot'], 'system_boundary' => $a['system_boundary'], 'impact_level' => $a['impact_level'], 'data_classifications' => $a['data_classifications'], 'control_snapshot' => $a['control_snapshot'], 'risk_snapshot' => $a['risk_snapshot'], 'open_findings' => $a['open_findings'], 'monitoring_strategy' => $a['monitoring_strategy'], 'review_frequency_days' => $a['review_frequency_days'], 'poam_reference' => $a['poam_reference'], 'change_summary' => $a['change_summary'], 'submitted_by' => $a['submitted_by'], 'submitted_at' => $at->toIso8601String()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))];
    }

    public static function applicationSnapshot(Application $application): array
    {
        $application->load(['owner:id,name,email', 'vendor:id,name']);
        $snapshot = $application->only(['id', 'name', 'type', 'description', 'status', 'url', 'notes']) + ['owner' => $application->owner?->only(['id', 'name', 'email']), 'vendor' => $application->vendor?->only(['id', 'name'])];

        return json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }
}
