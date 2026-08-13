<?php

namespace Tests\Feature;

use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditItem;
use App\Models\Control;
use App\Models\RemediationProject;
use App\Models\User;
use App\Remediation\Remediation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class RemediationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Config::set('enterprise.modules.remediation', true);
    }

    public function test_task_from_audit_item_stores_the_audit_item_fk(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole('Security Admin');
        $assignee = User::factory()->create();

        $audit = Audit::factory()->inProgress()->withManager($owner)->create();
        $item = AuditItem::factory()->create([
            'audit_id' => $audit->id,
            'auditable_type' => Control::class,
            'auditor_notes' => 'Encryption not applied to backup vault.',
            'status' => WorkflowStatus::INPROGRESS,
        ]);

        $project = app(Remediation::class)->createProject($owner, [
            'name' => 'Backup vault hardening',
        ]);

        $task = app(Remediation::class)->createTaskFromAuditItem($owner, $item, $project, [
            'priority' => 'High',
            'assignee_id' => $assignee->id,
        ]);

        $this->assertSame($item->id, $task->audit_item_id);
        $this->assertSame($project->id, $task->remediation_project_id);
        $this->assertStringStartsWith($project->code.'-', $task->number);
        $this->assertStringContainsString('Encryption not applied', (string) $task->weakness_description);
        $this->assertSame($assignee->id, $task->assignee_id);
    }

    public function test_member_cannot_see_another_remediation_project(): void
    {
        $alice = User::factory()->create();
        $alice->assignRole('Security Admin');
        $bob = User::factory()->create();
        $bob->assignRole('Security Admin');

        $aliceProject = app(Remediation::class)->createProject($alice, ['name' => 'Alice POA&M']);
        $bobProject = app(Remediation::class)->createProject($bob, ['name' => 'Bob POA&M']);

        $visibleToAlice = RemediationProject::query()->visibleTo($alice)->pluck('id');
        $visibleToBob = RemediationProject::query()->visibleTo($bob)->pluck('id');

        $this->assertTrue($visibleToAlice->contains($aliceProject->id));
        $this->assertFalse($visibleToAlice->contains($bobProject->id));
        $this->assertTrue($visibleToBob->contains($bobProject->id));
        $this->assertFalse($visibleToBob->contains($aliceProject->id));
    }
}
