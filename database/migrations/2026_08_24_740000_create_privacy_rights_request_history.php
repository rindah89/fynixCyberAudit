<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('privacy_rights_requests')) {
            Schema::create('privacy_rights_requests', function (Blueprint $table) {
                $table->id();
                $table->string('number')->unique();
                $table->string('request_type');
                $table->string('status');
                $table->string('data_subject_name');
                $table->string('data_subject_email')->nullable();
                $table->string('subject_reference')->nullable();
                $table->text('request_details');
                $table->string('intake_channel');
                $table->string('jurisdiction_reference')->nullable();
                $table->timestamp('received_at');
                $table->timestamp('due_at');
                $table->foreignId('assigned_to')->constrained('users')->restrictOnDelete();
                $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
                $table->text('identity_verification_summary')->nullable();
                $table->text('response_summary')->nullable();
                $table->text('decision_basis')->nullable();
                $table->string('delivery_reference')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('governed_at');
                $table->timestamps();
                $table->index(['assigned_to', 'status'], 'privacy_rights_assignee_status_idx');
            });
        }
        if (! Schema::hasTable('privacy_rights_request_events')) {
            Schema::create('privacy_rights_request_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('privacy_rights_request_id')->constrained()->restrictOnDelete();
                $table->unsignedSmallInteger('version');
                $table->string('from_status')->nullable();
                $table->string('to_status');
                $table->text('summary');
                $table->json('request_snapshot');
                $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['privacy_rights_request_id', 'version'], 'privacy_rights_event_version_uq');
            });
        }
    }

    public function down(): void
    {
        // Retain sensitive rights-request evidence during routine rollback.
    }
};
