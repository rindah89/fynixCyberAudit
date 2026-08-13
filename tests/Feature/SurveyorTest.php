<?php

namespace Tests\Feature;

use App\Ai\StubAiClient;
use App\Models\AiJob;
use App\Models\Policy;
use App\Models\User;
use App\Surveyor\Surveyor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;
use Tests\TestCase;

class SurveyorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.surveyor', true);
    }

    public function test_single_question_returns_labeled_overridable_verdict(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Security Admin');

        Policy::factory()->create([
            'name' => 'Backup Policy',
            'body' => 'Backups of customer data use AES-256-GCM in the quiet vault.',
        ]);

        $client = new StubAiClient;
        $client->queue(json_encode([
            'verdict' => 'Meets',
            'confidence' => 'HIGH',
            'coverage' => 'Full',
            'rationale' => 'Backup Policy states AES-256-GCM at rest.',
            'needs_human_review' => false,
        ]));
        $this->app->instance(StubAiClient::class, $client);

        $card = app(Surveyor::class)->answer($user, 'Do you encrypt customer backups at rest?');

        $this->assertSame('Meets', $card['verdict']);
        $this->assertSame('HIGH', $card['confidence']);
        $this->assertNotEmpty($card['evidence']);
        $this->assertSame('policy', $card['evidence'][0]['type']);
        $this->assertStringContainsString('AES-256-GCM', $card['rationale']);
        $this->assertFalse($card['needs_human_review']);
    }

    public function test_batch_csv_without_question_column_is_rejected(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Security Admin');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('question');

        app(Surveyor::class)->startBatch($user, "topic,notes\naccess,none\n", 'bad.csv');
    }

    public function test_batch_csv_writes_output_columns_and_cancel_stops_further_items(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Security Admin');

        $client = new StubAiClient;
        $client->queue(json_encode([
            'verdict' => 'Meets',
            'confidence' => 'HIGH',
            'coverage' => 'Full',
            'rationale' => 'First answer',
            'needs_human_review' => false,
        ]));
        $client->queue(json_encode([
            'verdict' => 'Does not meet',
            'confidence' => 'LOW',
            'coverage' => 'None',
            'rationale' => 'Should not run',
            'needs_human_review' => true,
        ]));
        $this->app->instance(StubAiClient::class, $client);

        $csv = "id,question\n1,Do you encrypt backups?\n2,Do you log admin access?\n";
        $batch = app(Surveyor::class)->startBatch($user, $csv, 'questions.csv');

        $this->assertSame(2, $batch->total);
        $this->assertSame(0, $batch->processed);

        app(Surveyor::class)->processNext($batch);
        $batch->refresh();
        $this->assertSame(1, $batch->processed);

        app(Surveyor::class)->cancel($user, $batch);
        app(Surveyor::class)->processNext($batch);
        $batch->refresh();

        $this->assertSame('cancelled', $batch->status);
        $this->assertSame(1, $batch->processed);
        $this->assertSame(1, $client->calls);

        $output = \Illuminate\Support\Facades\Storage::disk('local')->get($batch->result_path);
        $this->assertStringContainsString('verdict', $output);
        $this->assertStringContainsString('Meets', $output);
        $this->assertStringNotContainsString('Does not meet', $output);
    }
}
