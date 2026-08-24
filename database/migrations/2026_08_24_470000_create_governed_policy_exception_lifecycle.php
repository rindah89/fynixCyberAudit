<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('policy_exceptions', 'governance_snapshot')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->json('governance_snapshot')->nullable()->after('compensating_controls');
            });
        }
        if (! Schema::hasColumn('policy_exceptions', 'governance_fingerprint')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->char('governance_fingerprint', 64)->nullable()->after('governance_snapshot');
            });
        }
        if (! Schema::hasColumn('policy_exceptions', 'submitted_at')) {
            Schema::table('policy_exceptions', function (Blueprint $table): void {
                $table->timestamp('submitted_at')->nullable()->after('requested_date');
            });
        }
        if (! Schema::hasTable('policy_exception_decisions')) {
            Schema::create('policy_exception_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_exception_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('decision');
                $table->text('decision_summary');
                $table->json('exception_snapshot');
                $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('decided_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['policy_exception_id', 'version']);
            });
        }
    }

    public function down(): void
    {
        // Governed exception requests and decisions are retained during routine rollback.
    }
};
