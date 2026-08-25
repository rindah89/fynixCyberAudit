<?php

namespace Database\Factories;

use App\Models\Audit;
use App\Models\DataRequest;
use App\Models\DataRequestResponse;
use App\Models\FileAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FileAttachment> */
class FileAttachmentFactory extends Factory
{
    public function definition(): array
    {
        $actor = User::factory()->create();
        $audit = Audit::factory()->create(['manager_id' => $actor->id]);
        $request = DataRequest::factory()->create([
            'audit_id' => $audit->id,
            'created_by_id' => $actor->id,
            'assigned_to_id' => $actor->id,
        ]);
        $response = DataRequestResponse::factory()->accepted()->create([
            'data_request_id' => $request->id,
            'requester_id' => $actor->id,
            'requestee_id' => $actor->id,
        ]);

        return [
            'data_request_response_id' => $response->id,
            'audit_id' => $audit->id,
            'file_name' => fake()->unique()->lexify('evidence-????????.txt'),
            'file_path' => fake()->unique()->lexify('factory/evidence-????????.txt'),
            'file_size' => 24,
            'description' => 'Canonical accepted audit evidence attachment.',
            'uploaded_by' => $actor->id,
        ];
    }
}
