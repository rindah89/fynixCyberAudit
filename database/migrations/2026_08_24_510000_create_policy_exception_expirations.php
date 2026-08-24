<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('policy_exception_expirations')) {
            return;
        }

        Schema::create('policy_exception_expirations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('policy_exception_id')->unique()
                ->constrained(indexName: 'policy_exception_expirations_exception_fk')->restrictOnDelete();
            $table->string('prior_status');
            $table->date('expiration_date');
            $table->timestamp('expired_at');
            $table->timestamp('reconciled_at');
            $table->uuid('reconciliation_id')->index();
            $table->string('source');
            $table->json('exception_snapshot');
            $table->char('fingerprint', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Governed policy-exception expiration evidence is retained during routine code rollback.
    }
};
