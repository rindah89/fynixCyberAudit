<?php

namespace Tests\Feature;

use App\Access\VendorAccess;
use App\Models\Survey;
use App\Models\Vendor;
use App\Models\VendorUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class VendorAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unsigned_survey_access_is_rejected(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        $this->get('/portal/survey-access?survey='.$survey->id.'&email=invitee@example.com')
            ->assertForbidden();

        $this->assertDatabaseCount('vendor_users', 0);
    }

    public function test_unsigned_survey_access_cannot_register_a_vendor_user(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        Livewire::test(\App\Filament\Vendor\Pages\Auth\SurveyAccess::class, [
            'survey' => $survey->id,
            'email' => 'invitee@example.com',
        ])->assertStatus(401);

        $this->assertDatabaseCount('vendor_users', 0);
    }

    public function test_unsigned_register_via_module_is_rejected(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(VendorAccess::class)->register($survey, 'Pat', 'invitee@example.com', 'Password123!');

        $this->assertDatabaseCount('vendor_users', 0);
    }

    public function test_unsigned_set_password_via_module_is_rejected(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'pending@example.com',
        ]);
        $pending = VendorUser::factory()->pending()->create([
            'vendor_id' => $survey->vendor_id,
            'email' => 'pending@example.com',
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(VendorAccess::class)->setPassword($survey, $pending, 'Password123!');

        $this->assertNull($pending->fresh()->password);
    }

    public function test_signed_claim_allows_register_for_that_survey_and_email(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        $access = app(VendorAccess::class);
        $access->grantSurveyClaim($survey, 'invitee@example.com');

        $user = $access->register($survey, 'Pat Invitee', 'invitee@example.com', 'Password123!');

        $this->assertDatabaseHas('vendor_users', [
            'id' => $user->id,
            'email' => 'invitee@example.com',
            'vendor_id' => $survey->vendor_id,
            'name' => 'Pat Invitee',
        ]);
        $this->assertTrue($user->hasPassword());
    }

    public function test_signed_claim_allows_set_password_for_pending_user(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'pending@example.com',
        ]);
        $pending = VendorUser::factory()->pending()->create([
            'vendor_id' => $survey->vendor_id,
            'email' => 'pending@example.com',
        ]);

        $access = app(VendorAccess::class);
        $access->grantSurveyClaim($survey, 'pending@example.com');

        $access->setPassword($survey, $pending, 'Password123!');

        $this->assertTrue($pending->fresh()->hasPassword());
    }

    public function test_signed_magic_link_establishes_claim_and_redirects_to_signed_access(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        $response = $this->get($survey->getPublicUrl());

        $response->assertRedirect();
        $this->assertTrue(app(VendorAccess::class)->hasSurveyClaim($survey));
        $this->assertStringContainsString('/portal/survey-access', $response->headers->get('Location'));
        $this->assertStringContainsString('signature=', $response->headers->get('Location'));
    }

    public function test_signed_survey_access_url_is_reachable(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        $url = URL::temporarySignedRoute(
            'filament.vendor.pages.survey-access',
            now()->addHour(),
            ['survey' => $survey->id, 'email' => 'invitee@example.com']
        );

        $this->get($url)->assertSuccessful();
        $this->assertTrue(app(VendorAccess::class)->hasSurveyClaim($survey));
    }

    public function test_claim_cannot_register_a_different_email(): void
    {
        $survey = Survey::factory()->create([
            'respondent_email' => 'invitee@example.com',
        ]);

        app(VendorAccess::class)->grantSurveyClaim($survey, 'invitee@example.com');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(VendorAccess::class)->register($survey, 'Attacker', 'attacker@example.com', 'Password123!');
    }

    public function test_may_open_survey_matches_vendor_or_respondent(): void
    {
        $vendor = Vendor::factory()->create();
        $survey = Survey::factory()->create([
            'vendor_id' => $vendor->id,
            'respondent_email' => 'respondent@example.com',
        ]);

        $member = VendorUser::factory()->create(['vendor_id' => $vendor->id]);
        $respondent = VendorUser::factory()->create([
            'vendor_id' => Vendor::factory(),
            'email' => 'respondent@example.com',
        ]);
        $stranger = VendorUser::factory()->create();

        $access = app(VendorAccess::class);

        $this->assertTrue($access->mayOpenSurvey($member, $survey));
        $this->assertTrue($access->mayOpenSurvey($respondent, $survey));
        $this->assertFalse($access->mayOpenSurvey($stranger, $survey));
    }
}
