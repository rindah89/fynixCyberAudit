<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_legal_holds')) {
            Schema::create('compliance_case_legal_holds', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_id')->constrained('compliance_cases', indexName: 'cc_hold_case_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_event_id')->constrained('compliance_case_events', indexName: 'cc_hold_event_fk')->restrictOnDelete();
                $table->unsignedTinyInteger('version');
                $table->string('reference')->unique();
                $table->longText('scope');
                $table->json('systems');
                $table->json('data_categories');
                $table->text('legal_basis_reference')->nullable();
                $table->timestamp('preservation_start_at');
                $table->foreignId('issued_by')->constrained('users', indexName: 'cc_hold_issuer_fk')->restrictOnDelete();
                $table->json('issuer_snapshot');
                $table->json('custodian_snapshot');
                $table->json('case_snapshot');
                $table->json('latest_event_snapshot');
                $table->timestamp('issued_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->unique(['compliance_case_id', 'version'], 'cc_hold_case_version_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_legal_hold_custodians')) {
            Schema::create('compliance_case_legal_hold_custodians', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_legal_hold_id')->constrained('compliance_case_legal_holds', indexName: 'cc_hold_cust_hold_fk')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users', indexName: 'cc_hold_cust_user_fk')->restrictOnDelete();
                $table->json('recipient_snapshot');
                $table->timestamps();
                $table->unique(['compliance_case_legal_hold_id', 'user_id'], 'cc_hold_cust_user_uq');
            });
        }
        if (! Schema::hasTable('compliance_case_legal_hold_acknowledgements')) {
            Schema::create('compliance_case_legal_hold_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_legal_hold_id')->constrained('compliance_case_legal_holds', indexName: 'cc_hold_ack_hold_fk')->restrictOnDelete();
                $table->foreignId('compliance_case_legal_hold_custodian_id')->unique()->constrained('compliance_case_legal_hold_custodians', indexName: 'cc_hold_ack_cust_fk')->restrictOnDelete();
                $table->foreignId('user_id')->constrained('users', indexName: 'cc_hold_ack_user_fk')->restrictOnDelete();
                $table->json('hold_snapshot');
                $table->json('recipient_snapshot');
                $table->text('statement');
                $table->text('comment')->nullable();
                $table->timestamp('acknowledged_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('compliance_case_legal_hold_releases')) {
            Schema::create('compliance_case_legal_hold_releases', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_legal_hold_id')->unique()->constrained('compliance_case_legal_holds', indexName: 'cc_hold_rel_hold_fk')->restrictOnDelete();
                $table->foreignId('released_by')->constrained('users', indexName: 'cc_hold_rel_actor_fk')->restrictOnDelete();
                $table->json('actor_snapshot');
                $table->json('hold_snapshot');
                $table->json('custodian_acknowledgement_snapshot');
                $table->longText('summary');
                $table->timestamp('released_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Legal-hold instructions, custodian attribution, acknowledgements, and releases are retained on routine rollback.
    }
};
