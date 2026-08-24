<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_engagement_collaboration_requests')) {
            Schema::create('third_party_engagement_collaboration_requests', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_id')->constrained(indexName: 'tp_collab_request_engagement_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('category', 30);
                $table->string('subject');
                $table->text('request_text');
                $table->foreignId('recipient_vendor_user_id')->constrained('vendor_users', indexName: 'tp_collab_request_recipient_fk')->restrictOnDelete();
                $table->date('due_at');
                $table->json('engagement_snapshot');
                $table->json('recipient_snapshot');
                $table->foreignId('opened_by')->constrained('users', indexName: 'tp_collab_request_opener_fk')->restrictOnDelete();
                $table->timestamp('opened_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_id', 'version'], 'tp_collab_request_version_unique');
            });
        }
        if (! Schema::hasTable('third_party_engagement_collaboration_events')) {
            Schema::create('third_party_engagement_collaboration_events', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->constrained(indexName: 'tp_collab_event_request_fk')->restrictOnDelete();
                $table->unsignedInteger('version');
                $table->string('status', 30);
                $table->text('response_text')->nullable();
                $table->string('source_reference')->nullable();
                $table->text('summary')->nullable();
                $table->string('actor_type', 20);
                $table->unsignedBigInteger('actor_id');
                $table->json('actor_snapshot');
                $table->json('request_snapshot');
                $table->timestamp('recorded_at');
                $table->char('fingerprint', 64);
                $table->timestamps();
                $table->unique(['third_party_engagement_collaboration_request_id', 'version'], 'tp_collab_event_version_unique');
            });
        }
    }

    public function down(): void
    { /* Governed collaboration evidence is retained during routine rollback. */
    }
};
