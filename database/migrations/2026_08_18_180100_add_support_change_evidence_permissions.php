<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['review support change evidence', 'revoke support change evidence'] as $name) {
            DB::table('permissions')->updateOrInsert(['name' => $name, 'guard_name' => 'web'], ['updated_at' => now(), 'created_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['review support change evidence', 'revoke support change evidence'])->where('guard_name', 'web')->delete();
    }
};
