<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('system_authorization_packages', 'review_frequency_days')) {
            Schema::table('system_authorization_packages', fn (Blueprint $table) => $table->unsignedSmallInteger('review_frequency_days')->default(90)->after('monitoring_strategy'));
        }
        if (! Schema::hasTable('system_authorization_monitoring_reviews')) {
            Schema::create('system_authorization_monitoring_reviews', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('system_authorization_package_id')->constrained('system_authorization_packages', indexName: 'sys_auth_monitor_pkg_fk')->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->json('package_snapshot');
                $table->json('decision_snapshot');
                $table->json('metrics');
                $table->json('findings');
                $table->string('outcome', 40);
                $table->json('required_actions');
                $table->text('summary');
                $table->foreignId('reviewed_by')->constrained('users', indexName: 'sys_auth_monitor_actor_fk')->restrictOnDelete();
                $table->dateTime('reviewed_at');
                $table->date('next_review_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['system_authorization_package_id', 'version'], 'sys_auth_monitor_version_unique');
            });
        }
    }

    public function down(): void
    { /* Monitoring evidence is retained during routine rollback. */
    }
};
