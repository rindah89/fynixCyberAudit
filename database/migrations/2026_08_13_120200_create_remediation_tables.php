<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('remediation_projects')) {
            Schema::create('remediation_projects', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('planning');
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamps();
            });
        }

        if (! Schema::hasTable('remediation_project_members')) {
            Schema::create('remediation_project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remediation_project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member');
            $table->timestamps();
            $table->unique(['remediation_project_id', 'user_id'], 'remediation_project_member_unique');
            });
        }

        if (! Schema::hasTable('remediation_tasks')) {
            Schema::create('remediation_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remediation_project_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->string('title');
            $table->string('status')->default('Open');
            $table->string('priority')->default('Medium');
            $table->string('type')->default('Remediation');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->text('weakness_description')->nullable();
            $table->foreignId('audit_item_id')->nullable()->constrained('audit_items')->nullOnDelete();
            $table->timestamps();
            $table->unique('number');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('remediation_tasks');
        Schema::dropIfExists('remediation_project_members');
        Schema::dropIfExists('remediation_projects');
    }
};
