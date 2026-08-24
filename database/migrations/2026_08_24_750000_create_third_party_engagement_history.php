<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_engagements')) {
            Schema::create('third_party_engagements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
                $table->string('code');
                $table->string('name');
                $table->text('service_description');
                $table->foreignId('business_owner_id')->constrained('users')->restrictOnDelete();
                $table->string('criticality');
                $table->boolean('data_access')->default(false);
                $table->string('status');
                $table->foreignId('proposed_by')->constrained('users')->restrictOnDelete();
                $table->date('term_start_at');
                $table->date('term_end_at');
                $table->date('next_review_at');
                $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('exited_at')->nullable();
                $table->text('exit_summary')->nullable();
                $table->text('data_disposition_statement')->nullable();
                $table->json('vendor_snapshot');
                $table->json('approval_snapshot')->nullable();
                $table->timestamp('governed_at');
                $table->timestamps();
                $table->unique(['vendor_id', 'code'], 'third_party_engagement_vendor_code_uq');
            });
        }
        if (! Schema::hasTable('third_party_engagement_events')) {
            Schema::create('third_party_engagement_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('summary');
                $table->json('engagement_snapshot');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'third_party_engagement_event_version_uq');
            });
        }
    }

    public function down(): void
    { /* Retain engagement evidence during routine rollback. */
    }
};
