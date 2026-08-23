<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('risks', function (Blueprint $table) {
            $table->foreignId('parent_risk_id')->nullable()->after('domain')->constrained('risks')->restrictOnDelete();
        });

        Schema::create('risk_hierarchy_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_id')->constrained()->restrictOnDelete();
            $table->foreignId('previous_parent_risk_id')->nullable()->constrained('risks')->restrictOnDelete();
            $table->foreignId('parent_risk_id')->nullable()->constrained('risks')->restrictOnDelete();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();
        });

        Schema::create('risk_hierarchy_mutexes', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('risk_hierarchy_mutexes')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_hierarchy_mutexes');
        Schema::dropIfExists('risk_hierarchy_changes');
        Schema::table('risks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_risk_id');
        });
    }
};
