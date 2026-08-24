<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('third_party_collaboration_extensions')) {
            Schema::create('third_party_collaboration_extensions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_engagement_collaboration_request_id')->constrained('third_party_engagement_collaboration_requests', indexName: 'tp_collab_ext_request_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->date('proposed_due_at');
                $table->text('reason');
                $table->foreignId('recipient_vendor_user_id')->constrained('vendor_users', indexName: 'tp_collab_ext_recipient_fk')->restrictOnDelete();
                $table->json('recipient_snapshot');
                $table->json('request_snapshot');
                $table->json('current_due_context');
                $table->timestamp('requested_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['third_party_engagement_collaboration_request_id', 'version'], 'tp_collab_ext_request_version_unique');
            });
        }

        if (! Schema::hasTable('third_party_collaboration_extension_decisions')) {
            Schema::create('third_party_collaboration_extension_decisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('third_party_collaboration_extension_id')->unique()->constrained('third_party_collaboration_extensions', indexName: 'tp_collab_ext_decision_extension_fk')->restrictOnDelete();
                $table->string('decision');
                $table->text('summary');
                $table->foreignId('decided_by')->constrained('users', indexName: 'tp_collab_ext_decision_actor_fk')->restrictOnDelete();
                $table->json('decider_snapshot');
                $table->json('extension_snapshot');
                $table->timestamp('decided_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('third_party_engagement_collaboration_reminders', 'due_context_fingerprint')) {
            Schema::table('third_party_engagement_collaboration_reminders', fn (Blueprint $table): mixed => $table->char('due_context_fingerprint', 64)->nullable()->after('type'));
        }
        if (! Schema::hasColumn('third_party_engagement_collaboration_reminders', 'effective_due_at')) {
            Schema::table('third_party_engagement_collaboration_reminders', fn (Blueprint $table): mixed => $table->date('effective_due_at')->nullable()->after('due_context_fingerprint'));
        }
        if (! Schema::hasColumn('third_party_engagement_collaboration_reminders', 'due_context_snapshot')) {
            Schema::table('third_party_engagement_collaboration_reminders', fn (Blueprint $table): mixed => $table->json('due_context_snapshot')->nullable()->after('effective_due_at'));
        }
        DB::table('third_party_engagement_collaboration_reminders')->where(fn ($query) => $query->whereNull('due_context_fingerprint')->orWhereNull('effective_due_at')->orWhereNull('due_context_snapshot'))->orderBy('id')->eachById(function (object $reminder): void {
            $request = DB::table('third_party_engagement_collaboration_requests')->where('id', $reminder->third_party_engagement_collaboration_request_id)->first(['due_at', 'fingerprint']);
            if ($request) {
                $context = isset($reminder->due_context_snapshot) ? json_decode($reminder->due_context_snapshot, true, 512, JSON_THROW_ON_ERROR) : null;
                $decision = $context === null && isset($reminder->due_context_fingerprint)
                    ? DB::table('third_party_collaboration_extension_decisions')->where('fingerprint', $reminder->due_context_fingerprint)->first(['id', 'third_party_collaboration_extension_id', 'fingerprint'])
                    : null;
                if ($context === null && $decision === null && isset($reminder->effective_due_at)) {
                    $decision = DB::table('third_party_collaboration_extensions as extensions')
                        ->join('third_party_collaboration_extension_decisions as decisions', 'decisions.third_party_collaboration_extension_id', '=', 'extensions.id')
                        ->where('extensions.third_party_engagement_collaboration_request_id', $reminder->third_party_engagement_collaboration_request_id)
                        ->where('extensions.proposed_due_at', $reminder->effective_due_at)->where('decisions.decision', 'approved')
                        ->orderByDesc('extensions.version')->first(['extensions.id as third_party_collaboration_extension_id', 'decisions.id', 'decisions.fingerprint']);
                }
                $extension = $decision ? DB::table('third_party_collaboration_extensions')->where('id', $decision->third_party_collaboration_extension_id)->first(['id', 'proposed_due_at']) : null;
                $context ??= $extension
                    ? ['due_at' => $extension->proposed_due_at, 'fingerprint' => $decision->fingerprint, 'extension_id' => $extension->id, 'decision_id' => $decision->id]
                    : ['due_at' => $request->due_at, 'fingerprint' => $request->fingerprint, 'extension_id' => null, 'decision_id' => null];
                DB::table('third_party_engagement_collaboration_reminders')->where('id', $reminder->id)->update([
                    'due_context_fingerprint' => $reminder->due_context_fingerprint ?? $context['fingerprint'],
                    'effective_due_at' => $reminder->effective_due_at ?? $context['due_at'],
                    'due_context_snapshot' => $reminder->due_context_snapshot ?? json_encode($context, JSON_THROW_ON_ERROR),
                ]);
            }
        });
        if (! Schema::hasColumn('third_party_engagement_collaboration_escalations', 'effective_due_at')) {
            Schema::table('third_party_engagement_collaboration_escalations', fn (Blueprint $table): mixed => $table->date('effective_due_at')->nullable()->after('vendor_user_id'));
        }
        if (! Schema::hasColumn('third_party_engagement_collaboration_escalations', 'due_context_snapshot')) {
            Schema::table('third_party_engagement_collaboration_escalations', fn (Blueprint $table): mixed => $table->json('due_context_snapshot')->nullable()->after('effective_due_at'));
        }
        DB::table('third_party_engagement_collaboration_escalations')->where(fn ($query) => $query->whereNull('effective_due_at')->orWhereNull('due_context_snapshot'))->orderBy('id')->eachById(function (object $escalation): void {
            $request = DB::table('third_party_engagement_collaboration_requests')->where('id', $escalation->third_party_engagement_collaboration_request_id)->first(['due_at', 'fingerprint']);
            if ($request) {
                $decision = isset($escalation->effective_due_at) ? DB::table('third_party_collaboration_extensions as extensions')
                    ->join('third_party_collaboration_extension_decisions as decisions', 'decisions.third_party_collaboration_extension_id', '=', 'extensions.id')
                    ->where('extensions.third_party_engagement_collaboration_request_id', $escalation->third_party_engagement_collaboration_request_id)
                    ->where('extensions.proposed_due_at', $escalation->effective_due_at)->where('decisions.decision', 'approved')
                    ->orderByDesc('extensions.version')->first(['extensions.id as extension_id', 'decisions.id as decision_id', 'decisions.fingerprint']) : null;
                $context = isset($escalation->due_context_snapshot)
                    ? json_decode($escalation->due_context_snapshot, true, 512, JSON_THROW_ON_ERROR)
                    : ($decision
                        ? ['due_at' => $escalation->effective_due_at, 'fingerprint' => $decision->fingerprint, 'extension_id' => $decision->extension_id, 'decision_id' => $decision->decision_id]
                        : ['due_at' => $request->due_at, 'fingerprint' => $request->fingerprint, 'extension_id' => null, 'decision_id' => null]);
                DB::table('third_party_engagement_collaboration_escalations')->where('id', $escalation->id)->update([
                    'effective_due_at' => $escalation->effective_due_at ?? $context['due_at'],
                    'due_context_snapshot' => $escalation->due_context_snapshot ?? json_encode($context, JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Due-date decision, reminder, and escalation evidence is retained during routine code rollback.
    }
};
