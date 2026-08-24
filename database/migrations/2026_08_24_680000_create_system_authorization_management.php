<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('system_authorization_packages')) {
            Schema::create('system_authorization_packages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('application_id')->constrained('applications', indexName: 'sys_auth_pkg_app_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('application_snapshot');
                $table->text('system_boundary');
                $table->string('impact_level', 20);
                $table->json('data_classifications');
                $table->json('control_snapshot');
                $table->json('risk_snapshot');
                $table->json('open_findings');
                $table->text('monitoring_strategy');
                $table->text('poam_reference')->nullable();
                $table->text('change_summary');
                $table->foreignId('submitted_by')->constrained('users', indexName: 'sys_auth_pkg_submitter_fk')->restrictOnDelete();
                $table->dateTime('submitted_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['application_id', 'version'], 'sys_auth_pkg_app_version_unique');
            });
        }
        if (! Schema::hasTable('system_authorization_decisions')) {
            Schema::create('system_authorization_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('system_authorization_package_id')->constrained('system_authorization_packages', indexName: 'sys_auth_decision_pkg_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('package_snapshot');
                $table->string('decision', 40);
                $table->json('conditions');
                $table->text('rationale');
                $table->foreignId('decided_by')->constrained('users', indexName: 'sys_auth_decision_actor_fk')->restrictOnDelete();
                $table->dateTime('decided_at');
                $table->date('valid_until')->nullable();
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['system_authorization_package_id', 'version'], 'sys_auth_decision_version_unique');
            });
        }
    }

    public function down(): void
    { /* Authorization evidence is retained during routine rollback. */
    }
};
