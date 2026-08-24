<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendor_fourth_party_dependencies')) {
            return;
        }

        Schema::create('vendor_fourth_party_dependencies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('fourth_party_vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->foreignId('business_service_id')->nullable()->constrained('business_services')->restrictOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->string('dependency_key', 80);
            $table->unsignedInteger('version');
            $table->string('status');
            $table->string('category');
            $table->string('criticality');
            $table->string('fourth_party_name');
            $table->text('service_description');
            $table->boolean('data_access')->default(false);
            $table->string('source_reference')->nullable();
            $table->text('rationale');
            $table->json('governance_snapshot');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['vendor_id', 'dependency_key', 'version'], 'vendor_fourth_party_version_unique');
            $table->index(['dependency_key', 'version']);
            $table->index(['vendor_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        // Fourth-party governance history is retained during routine rollback.
    }
};
