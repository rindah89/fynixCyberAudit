<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Bundle;
use App\Models\Control;
use App\Models\DataRequestResponse;
use App\Models\Standard;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WriteSeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_control_create_ignores_unexpected_keys(): void
    {
        $control = new Control([
            'title' => 'Access Control',
            'code' => 'AC-1',
            'description' => 'Desc',
            'standard_id' => Standard::factory()->create()->id,
            'deleted_at' => now(),
        ]);

        $this->assertNull($control->deleted_at);
        $control->save();
        $this->assertNull($control->fresh()->deleted_at);
    }

    public function test_standard_create_ignores_unexpected_keys(): void
    {
        $standard = new Standard([
            'name' => 'NIST',
            'description' => 'Framework',
            'code' => 'NIST-1',
            'authority' => 'NIST',
            'deleted_at' => now(),
        ]);

        $this->assertNull($standard->deleted_at);
        $standard->save();
        $this->assertNull($standard->fresh()->deleted_at);
    }

    public function test_bundle_create_ignores_unexpected_keys(): void
    {
        $bundle = Bundle::factory()->create();

        $bundle->fill(['deleted_at' => now(), 'code' => 'B-SAFE']);
        $bundle->save();

        $this->assertSame('B-SAFE', $bundle->fresh()->code);
        $this->assertNull($bundle->fresh()->deleted_at);
    }

    public function test_data_request_response_create_ignores_unexpected_keys(): void
    {
        $response = new DataRequestResponse([
            'response' => 'ok',
            'not_a_column_flag' => true,
        ]);

        $this->assertArrayNotHasKey('not_a_column_flag', $response->getAttributes());
    }

    public function test_rest_show_authorizes_the_record_instance(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $requestee = User::factory()->create();
        $stranger = User::factory()->create();
        $requestee->givePermissionTo('Read DataRequestResponses');
        $stranger->givePermissionTo('Read DataRequestResponses');

        $response = DataRequestResponse::factory()->create([
            'requestee_id' => $requestee->id,
        ]);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/data-request-responses/'.$response->id)
            ->assertForbidden();

        Sanctum::actingAs($requestee);
        $this->getJson('/api/data-request-responses/'.$response->id)
            ->assertOk()
            ->assertJsonPath('data.id', $response->id);
    }

    public function test_rest_show_ignores_arbitrary_with_relations(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $requestee = User::factory()->create();
        $requestee->givePermissionTo('Read DataRequestResponses');

        $response = DataRequestResponse::factory()->create([
            'requestee_id' => $requestee->id,
        ]);

        Sanctum::actingAs($requestee);

        $this->getJson('/api/data-request-responses/'.$response->id.'?with=thisRelationDoesNotExist,roles')
            ->assertOk()
            ->assertJsonPath('data.id', $response->id);
    }

    public function test_audit_show_authorizes_the_instance_and_ignores_caller_with(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $manager = User::factory()->create();
        $manager->givePermissionTo('Read Audits');
        $stranger = User::factory()->create();

        $audit = Audit::factory()->create([
            'manager_id' => $manager->id,
        ]);
        $audit->members()->attach($stranger->id);

        Sanctum::actingAs($stranger);
        $this->getJson('/api/audits/'.$audit->id)
            ->assertForbidden();

        Sanctum::actingAs($manager);
        $response = $this->getJson('/api/audits/'.$audit->id.'?with=members,thisRelationDoesNotExist');

        $response->assertOk()
            ->assertJsonPath('data.id', $audit->id);

        $payload = $response->json('data');
        $this->assertArrayNotHasKey('members', $payload);
        $this->assertArrayNotHasKey('this_relation_does_not_exist', $payload);
        $this->assertArrayNotHasKey('thisRelationDoesNotExist', $payload);
    }
}
