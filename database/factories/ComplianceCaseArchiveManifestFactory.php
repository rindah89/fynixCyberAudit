<?php

namespace Database\Factories;

use App\Access\FileAccess;
use App\ComplianceCases\ComplianceCaseArchiveManager;
use App\Models\ComplianceCaseArchiveManifest;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ComplianceCaseArchiveManifest> */
class ComplianceCaseArchiveManifestFactory extends Factory
{
    protected $model = ComplianceCaseArchiveManifest::class;

    public function definition(): array
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $review = ComplianceCaseClosureReportReview::factory()->create([
            'compliance_case_closure_report_id' => $package->id,
        ]);
        $generator = User::factory()->create();
        $generator->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $generatedAt = now()->startOfSecond();
        $sources = [
            'schema_version' => 'archive/v1', 'summary' => 'Factory archive package.',
            'closure_package' => $package->fingerprint, 'closure_package_review' => $review->fingerprint,
            'conflicts' => [], 'conflict_decisions' => [], 'access_grants' => [],
            'access_grant_revocations' => [], 'milestones' => [], 'milestone_events' => [],
            'milestone_deliveries' => [],
            'communications' => [], 'retention_classifications' => [], 'disposition_reviews' => [],
            'reopen_proposals' => [], 'reopen_reviews' => [],
            'closure_source_fingerprints' => data_get($package->report_snapshot, 'source_fingerprints', []),
            'files' => [['kind' => 'closure_package_pdf', 'size' => $package->report_size, 'sha256' => $package->report_sha256]],
        ];
        $temporary = tempnam(sys_get_temp_dir(), 'cc-archive-factory-');
        $zip = new \ZipArchive;
        $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('manifest.json', CanonicalJson::encode($sources));
        $zip->addFromString('files/closure-package.pdf', app(FileAccess::class)->verifiedComplianceCaseClosureReport($package));
        $zip->close();
        $bytes = file_get_contents($temporary) ?: '';
        @unlink($temporary);
        $path = 'compliance-case-archives/'.Str::uuid().'.zip';
        app(FileAccess::class)->putPrivate('private', $path, $bytes);

        return [
            'compliance_case_id' => $package->compliance_case_id,
            'compliance_case_closure_report_id' => $package->id, 'version' => 1,
            'source_fingerprints' => $sources, 'archive_disk' => 'private', 'archive_path' => $path,
            'archive_size' => strlen($bytes), 'archive_sha256' => hash('sha256', $bytes),
            'schema_version' => 'archive/v1', 'generated_by' => $generator->id,
            'generator_snapshot' => $generator->only(['id', 'name', 'email']),
            'generated_at' => $generatedAt, 'fingerprint' => str_repeat('0', 64),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (ComplianceCaseArchiveManifest $archive): void {
            $archive->fingerprint = hash('sha256', CanonicalJson::encode(
                app(ComplianceCaseArchiveManager::class)->payload($archive),
            ));
        });
    }
}
