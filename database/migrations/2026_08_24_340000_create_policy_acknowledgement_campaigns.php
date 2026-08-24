<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_acknowledgement_campaigns')) {
            Schema::create('policy_acknowledgement_campaigns', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_id')->constrained()->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('title');
                $table->text('instructions')->nullable();
                $table->timestamp('due_at');
                $table->foreignId('launched_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('launched_at');
                $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp('closed_at')->nullable();
                $table->json('policy_snapshot');
                $table->char('policy_fingerprint', 64);
                $table->timestamps();
                $table->unique(['policy_id', 'version']);
                $table->index(['due_at', 'closed_at']);
            });
        }

        if (! Schema::hasTable('policy_acknowledgement_assignments')) {
            Schema::create('policy_acknowledgement_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_acknowledgement_campaign_id')->constrained()->restrictOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->timestamp('assigned_at');
                $table->timestamps();
                $table->unique(['policy_acknowledgement_campaign_id', 'user_id'], 'policy_ack_campaign_user_unique');
                $table->index(['user_id', 'assigned_at']);
            });
        }

        if (! Schema::hasTable('policy_acknowledgements')) {
            Schema::create('policy_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('policy_acknowledgement_assignment_id')->unique('policy_ack_assignment_unique')->constrained()->restrictOnDelete();
                $table->foreignId('acknowledged_by')->constrained('users')->restrictOnDelete();
                $table->text('statement');
                $table->text('comment')->nullable();
                $table->string('client_reference')->nullable();
                $table->json('campaign_snapshot');
                $table->json('policy_snapshot');
                $table->char('policy_fingerprint', 64);
                $table->timestamp('acknowledged_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Policy acknowledgement history is retained during routine rollback.
    }
};
