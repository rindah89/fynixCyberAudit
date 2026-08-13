<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Enums\QuestionType;
use App\Models\Survey;
use App\Models\SurveyAnswer;
use App\Models\SurveyAttachment;
use App\Models\SurveyQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_cannot_download_unknown_private_path(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('secrets/other-party.txt', 'classified');

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get('/app/priv-storage/secrets/other-party.txt')
            ->assertForbidden();
    }

    public function test_staff_cannot_download_another_partys_evidence_by_path(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('evidence/owner-file.txt', 'owner bytes');

        $owner = User::factory()->create();
        $staff = User::factory()->create();
        $audit = Audit::factory()->create(['manager_id' => $owner->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id,
            'created_by_id' => $owner->id,
            'assigned_to_id' => $owner->id,
        ]);
        $response = DataRequestResponse::factory()->create([
            'data_request_id' => $request->id,
            'requester_id' => $owner->id,
            'requestee_id' => $owner->id,
        ]);

        FileAttachment::create([
            'data_request_response_id' => $response->id,
            'audit_id' => $audit->id,
            'file_name' => 'owner-file.txt',
            'file_path' => 'evidence/owner-file.txt',
            'file_size' => 11,
            'description' => 'Owner evidence',
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($staff)
            ->get('/app/priv-storage/evidence/owner-file.txt')
            ->assertForbidden();
    }

    public function test_owner_can_download_their_private_file(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('evidence/owner-file.txt', 'owner bytes');

        $owner = User::factory()->create();
        $audit = Audit::factory()->create(['manager_id' => $owner->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id,
            'created_by_id' => $owner->id,
            'assigned_to_id' => $owner->id,
        ]);
        $response = DataRequestResponse::factory()->create([
            'data_request_id' => $request->id,
            'requester_id' => $owner->id,
            'requestee_id' => $owner->id,
        ]);

        FileAttachment::create([
            'data_request_response_id' => $response->id,
            'audit_id' => $audit->id,
            'file_name' => 'owner-file.txt',
            'file_path' => 'evidence/owner-file.txt',
            'file_size' => 11,
            'description' => 'Owner evidence',
            'uploaded_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->get('/app/priv-storage/evidence/owner-file.txt')
            ->assertSuccessful();
    }

    public function test_staff_cannot_download_another_partys_survey_attachment(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('surveys/answer.bin', 'survey bytes');

        $uploader = User::factory()->create();
        $staff = User::factory()->create();
        $survey = Survey::factory()->create();
        $question = SurveyQuestion::create([
            'survey_template_id' => $survey->survey_template_id,
            'question_text' => 'Upload evidence',
            'question_type' => QuestionType::FILE,
        ]);
        $answer = SurveyAnswer::create([
            'survey_id' => $survey->id,
            'survey_question_id' => $question->id,
            'answer_value' => ['value' => 'yes'],
        ]);

        $attachment = SurveyAttachment::create([
            'survey_answer_id' => $answer->id,
            'file_name' => 'answer.bin',
            'file_path' => 'surveys/answer.bin',
            'file_size' => '12',
            'uploaded_by' => $uploader->id,
        ]);

        $this->actingAs($staff)
            ->get(route('survey-attachment.download', $attachment))
            ->assertForbidden();
    }

    public function test_uploader_can_download_survey_attachment(): void
    {
        Storage::fake('private');
        Storage::disk('private')->put('surveys/answer.bin', 'survey bytes');

        $uploader = User::factory()->create();
        $survey = Survey::factory()->create();
        $question = SurveyQuestion::create([
            'survey_template_id' => $survey->survey_template_id,
            'question_text' => 'Upload evidence',
            'question_type' => QuestionType::FILE,
        ]);
        $answer = SurveyAnswer::create([
            'survey_id' => $survey->id,
            'survey_question_id' => $question->id,
            'answer_value' => ['value' => 'yes'],
        ]);

        $attachment = SurveyAttachment::create([
            'survey_answer_id' => $answer->id,
            'file_name' => 'answer.bin',
            'file_path' => 'surveys/answer.bin',
            'file_size' => '12',
            'uploaded_by' => $uploader->id,
        ]);

        $this->actingAs($uploader)
            ->get(route('survey-attachment.download', $attachment))
            ->assertSuccessful();
    }

    public function test_media_proxy_denies_unmapped_path(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('media/secret.png', 'png');

        $staff = User::factory()->create();

        $this->actingAs($staff)
            ->get('/media/media/secret.png')
            ->assertForbidden();
    }
}
