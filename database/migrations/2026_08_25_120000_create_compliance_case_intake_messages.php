<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_intake_messages')) {
            Schema::create('compliance_case_intake_messages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_intake_id')->constrained('compliance_case_intakes', indexName: 'cc_int_msg_intake_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->string('audience');
                $table->longText('message');
                $table->foreignId('actor_id')->constrained('users', indexName: 'cc_int_msg_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->json('intake_snapshot');
                $table->json('disposition_snapshot')->nullable();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_intake_id', 'version'], 'cc_int_msg_version_uq');
            });
        }
    }

    public function down(): void
    {
        // Governed reporter-visible and internal intake correspondence is retained on routine rollback.
    }
};
