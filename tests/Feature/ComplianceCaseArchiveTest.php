<?php

namespace Tests\Feature;

use App\Access\FileAccess;
use App\ComplianceCases\ComplianceCaseArchiveManager;
use App\Models\ComplianceCaseArchiveManifest;
use App\Models\ComplianceCaseClosureReport;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ComplianceCaseArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
        Storage::fake('private');
    }

    public function test_archive_manifest_requires_independent_approval_and_verified_download(): void
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $generator = User::factory()->create();
        $generator->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $packageReviewer = User::factory()->create();
        $packageReviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);

        $this->actingAs($generator)->postJson("/api/compliance-cases/{$package->compliance_case_id}/archive-manifests", [
            'summary' => 'Unapproved packages cannot be archived.',
        ])->assertUnprocessable();

        $this->actingAs($packageReviewer)->postJson("/api/compliance-case-closure-reports/{$package->id}/review", [
            'decision' => 'approved', 'summary' => 'The retained package is approved for archive.',
        ])->assertCreated();

        $this->actingAs($generator)->postJson("/api/compliance-cases/{$package->compliance_case_id}/archive-manifests", [
            'summary' => 'Package the current governed closure evidence.',
            'fingerprint' => str_repeat('a', 64),
        ])->assertUnprocessable();

        $id = $this->actingAs($generator)->postJson("/api/compliance-cases/{$package->compliance_case_id}/archive-manifests", [
            'summary' => 'Package the current governed closure evidence.',
        ])->assertCreated()->json('data.id');
        $archive = ComplianceCaseArchiveManifest::query()->findOrFail($id);
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseArchiveManager::class)->payload($archive))),
            $archive->fingerprint,
        );
        $this->assertSame($package->fingerprint, $archive->source_fingerprints['closure_package']);
        $this->assertSame(
            $package->report_sha256,
            collect($archive->source_fingerprints['files'])->firstWhere('kind', 'closure_package_pdf')['sha256'],
        );
        $this->assertArrayHasKey('conflicts', $archive->source_fingerprints);
        $this->assertArrayHasKey('access_grants', $archive->source_fingerprints);
        $this->assertArrayHasKey('milestones', $archive->source_fingerprints);
        $this->assertArrayHasKey('milestone_deliveries', $archive->source_fingerprints);
        $this->assertArrayHasKey('communications', $archive->source_fingerprints);
        $this->assertArrayHasKey('retention_classifications', $archive->source_fingerprints);
        $this->assertArrayHasKey('reopen_proposals', $archive->source_fingerprints);
        Storage::disk('private')->assertExists($archive->archive_path);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('private')->path($archive->archive_path)) === true);
        $this->assertNotFalse($zip->locateName('manifest.json'));
        $this->assertSame(
            Storage::disk('private')->get($package->report_path),
            $zip->getFromName('files/closure-package.pdf'),
        );
        $zip->close();

        $this->actingAs($generator)->getJson("/api/compliance-cases/{$package->compliance_case_id}/archive-manifests")
            ->assertOk()->assertJsonPath('data.0.id', $id)
            ->assertJsonMissingPath('data.0.archive_path')
            ->assertJsonMissingPath('data.0.source_fingerprints');

        $this->actingAs($generator)->get(route('compliance-case-archives.download', $archive))->assertForbidden();
        $this->actingAs($reviewer)->postJson("/api/compliance-case-archive-manifests/{$id}/review", [
            'decision' => 'approved', 'summary' => 'The archive matches the retained closure package.',
        ])->assertCreated();
        $this->actingAs($reviewer)->get(route('compliance-case-archives.download', $archive))->assertOk();
        Storage::disk('private')->put($archive->archive_path, 'tampered archive');
        $this->actingAs($reviewer)->get(route('compliance-case-archives.download', $archive))->assertStatus(409);
        $this->assertThrows(
            fn () => app(FileAccess::class)->deleteUnreferencedFileAttachmentPath('private', $archive->archive_path),
            ValidationException::class,
        );
    }

    public function test_canonical_archive_factory_retains_real_package_bytes_and_reconstructs_fingerprint(): void
    {
        $archive = ComplianceCaseArchiveManifest::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseArchiveManager::class)->payload($archive))),
            $archive->fingerprint,
        );
        Storage::disk('private')->assertExists($archive->archive_path);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('private')->path($archive->archive_path)) === true);
        $this->assertNotFalse($zip->locateName('files/closure-package.pdf'));
        $zip->close();
    }
}
