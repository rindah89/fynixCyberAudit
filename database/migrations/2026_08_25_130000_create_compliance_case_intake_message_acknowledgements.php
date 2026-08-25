<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compliance_case_intake_message_acknowledgements')) {
            Schema::create('compliance_case_intake_message_acknowledgements', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('compliance_case_intake_message_id')->unique()->constrained('compliance_case_intake_messages', indexName: 'cc_int_ack_message_fk')->restrictOnDelete();
                $table->foreignId('recipient_id')->constrained('users', indexName: 'cc_int_ack_recipient_fk')->restrictOnDelete();
                $table->json('recipient_snapshot');
                $table->json('message_snapshot');
                $table->timestamp('acknowledged_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Exact-recipient acknowledgement evidence is retained on routine rollback.
    }
};
