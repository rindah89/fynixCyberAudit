<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_playbooks', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('incident_type')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_playbook_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_playbook_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('phase');
            $table->string('priority')->default('Medium');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('title');
            $table->string('type')->nullable();
            $table->string('severity')->default('Medium');
            $table->string('status')->default('Open');
            $table->string('phase')->default('Identification');
            $table->foreignId('lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('detected_at')->nullable();
            $table->boolean('involves_data')->default(false);
            $table->boolean('involves_pii')->default(false);
            $table->boolean('is_breach')->default(false);
            $table->text('root_cause')->nullable();
            $table->text('business_impact')->nullable();
            $table->text('closure')->nullable();
            $table->json('phase_timestamps')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('phase');
            $table->string('status')->default('Open');
            $table->string('priority')->default('Medium');
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('incident_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('file');
            $table->string('filename');
            $table->string('path');
            $table->string('hash');
            $table->string('phase');
            $table->string('source')->nullable();
            $table->boolean('chain_of_custody')->default(true);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_evidence');
        Schema::dropIfExists('incident_tasks');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('incident_playbook_tasks');
        Schema::dropIfExists('incident_playbooks');
    }
};
