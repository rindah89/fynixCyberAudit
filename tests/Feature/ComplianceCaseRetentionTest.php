<?php

namespace Tests\Feature;

use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Models\ComplianceCaseClosureReport;
use App\Models\ComplianceCaseDispositionReview;
use App\Models\ComplianceCaseRetentionClassification;
use App\Models\User;
use App\Support\CanonicalJson;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ComplianceCaseRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.compliance_cases', true);
        Storage::fake('private');
    }

    public function test_closed_case_receives_retention_classification_and_independent_disposition_without_delete(): void
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $classifier = User::factory()->create();
        $classifier->assignRole('Security Admin');
        $reviewer = User::factory()->create();
        $reviewer->assignRole('Security Admin');
        $packageReviewer = User::factory()->create();
        $packageReviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);

        $this->actingAs($classifier)->postJson("/api/compliance-cases/{$package->compliance_case_id}/retention-classifications", [
            'policy_reference' => 'RET-CASE-7',
            'classification' => 'retain_7_years',
            'starts_on' => '2026-09-01',
            'ends_on' => '2033-09-01',
            'rationale' => 'Statutory investigation record retention.',
        ])->assertUnprocessable();

        $this->actingAs($packageReviewer)->postJson("/api/compliance-case-closure-reports/{$package->id}/review", [
            'decision' => 'approved', 'summary' => 'Package approved for retention classification.',
        ])->assertCreated();

        $id = $this->actingAs($classifier)->postJson("/api/compliance-cases/{$package->compliance_case_id}/retention-classifications", [
            'policy_reference' => 'RET-CASE-7',
            'classification' => 'retain_7_years',
            'starts_on' => '2026-09-01',
            'ends_on' => '2033-09-01',
            'rationale' => 'Statutory investigation record retention.',
        ])->assertCreated()->json('data.id');
        $classification = ComplianceCaseRetentionClassification::query()->findOrFail($id);
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseRetentionManager::class)->payload($classification))),
            $classification->fingerprint,
        );
        $this->assertSame($package->fingerprint, $classification->case_snapshot['closure_package_fingerprint']);

        $this->actingAs($classifier)->postJson("/api/compliance-case-retention-classifications/{$id}/disposition", [
            'decision' => 'approved', 'summary' => 'Self disposition is forbidden.',
        ])->assertForbidden();

        $this->actingAs($reviewer)->postJson("/api/compliance-case-retention-classifications/{$id}/disposition", [
            'decision' => 'approved', 'summary' => 'Disposition may proceed; no records are deleted.',
        ])->assertCreated();
        $this->assertDatabaseHas('compliance_cases', ['id' => $package->compliance_case_id, 'status' => 'Closed']);
        $this->assertDatabaseHas('compliance_case_retention_classifications', ['id' => $id]);
        $this->assertNotNull($classification->fresh()->disposition);
    }

    public function test_retention_dates_are_normalized_before_ordering(): void
    {
        $package = ComplianceCaseClosureReport::factory()->create();
        $actor = User::factory()->create();
        $actor->assignRole('Security Admin');
        $reviewer = User::factory()->create();
        $reviewer->givePermissionTo(['Manage Compliance Cases', 'Read Compliance Cases']);
        $this->actingAs($reviewer)->postJson("/api/compliance-case-closure-reports/{$package->id}/review", [
            'decision' => 'approved', 'summary' => 'Approved.',
        ])->assertCreated();

        $this->actingAs($actor)->postJson("/api/compliance-cases/{$package->compliance_case_id}/retention-classifications", [
            'policy_reference' => 'RET-DATE', 'classification' => 'retain',
            'starts_on' => 'January 1 2027', 'ends_on' => 'December 1 2027', 'rationale' => 'Mixed formats.',
        ])->assertCreated();
        $this->assertDatabaseHas('compliance_case_retention_classifications', [
            'compliance_case_id' => $package->compliance_case_id,
            'starts_on' => '2027-01-01 00:00:00', 'ends_on' => '2027-12-01 00:00:00',
        ]);
    }

    public function test_retention_and_disposition_factories_reconstruct_production_fingerprints(): void
    {
        $classification = ComplianceCaseRetentionClassification::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseRetentionManager::class)->payload($classification))),
            $classification->fingerprint,
        );
        $review = ComplianceCaseDispositionReview::factory()->create();
        $this->assertSame(
            hash('sha256', CanonicalJson::encode(app(ComplianceCaseRetentionManager::class)->reviewPayload($review))),
            $review->fingerprint,
        );
    }
}
