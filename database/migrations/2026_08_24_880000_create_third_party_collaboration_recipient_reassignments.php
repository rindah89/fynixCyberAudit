<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_collaboration_recipient_reassignments')) {
            Schema::create('third_party_collaboration_recipient_reassignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_reassign_request_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->foreignId('from_vendor_user_id')->constrained('vendor_users', indexName: 'tp_collab_reassign_from_fk')->restrictOnDelete();
                $table->foreignId('to_vendor_user_id')->constrained('vendor_users', indexName: 'tp_collab_reassign_to_fk')->restrictOnDelete();
                $table->json('from_recipient_snapshot');
                $table->json('to_recipient_snapshot');
                $table->json('prior_recipient_context');
                $table->json('request_snapshot');
                $table->text('reason');
                $table->foreignId('reassigned_by')->constrained('users', indexName: 'tp_collab_reassign_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->timestamp('reassigned_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['third_party_engagement_collaboration_request_id', 'version'], 'tp_collab_reassign_request_version_unique');
            });
        }
    }

    public function down(): void
    {
        // Recipient-reassignment evidence is retained during routine code rollback.
    }
};
