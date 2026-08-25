<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_intake_mutexes')) {
            Schema::create('compliance_case_intake_mutexes', function (Blueprint $table): void {
                $table->unsignedTinyInteger('id')->primary();
            });
        }
        DB::table('compliance_case_intake_mutexes')->insertOrIgnore(['id' => 1]);
        if (! Schema::hasTable('compliance_case_intakes')) {
            Schema::create('compliance_case_intakes', function (Blueprint $table): void {
                $table->id();
                $table->string('reference')->unique();
                $table->string('title');
                $table->string('category');
                $table->string('priority');
                $table->longText('allegation');
                $table->string('source_channel', 100);
                $table->text('source_reference')->nullable();
                $table->boolean('confidential')->default(true);
                $table->longText('reporter_message')->nullable();
                $table->foreignId('submitted_by')->constrained('users', indexName: 'cc_intake_reporter_fk')->restrictOnDelete();
                $table->json('reporter_snapshot');
                $table->timestamp('submitted_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('compliance_case_intake_dispositions')) {
            Schema::create('compliance_case_intake_dispositions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_intake_id')->unique()->constrained('compliance_case_intakes', indexName: 'cc_intake_disp_intake_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_id')->nullable()->unique()->constrained('compliance_cases', indexName: 'cc_intake_disp_case_fk')->restrictOnDelete();
                $table->string('decision');
                $table->longText('summary');
                $table->foreignId('decided_by')->constrained('users', indexName: 'cc_intake_disp_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->json('intake_snapshot');
                $table->json('case_snapshot')->nullable();
                $table->timestamp('decided_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Governed intake submissions, dispositions, case links, and their allocation mutex are retained on routine rollback.
    }
};
