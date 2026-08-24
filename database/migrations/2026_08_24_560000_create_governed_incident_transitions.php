<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('incident_number_sequences')) {
            Schema::create('incident_number_sequences', function (Blueprint $table) {
                $table->unsignedSmallInteger('year')->primary();
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('incidents', 'incident_playbook_id')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->foreignId('incident_playbook_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            });
        }

        if (! Schema::hasColumn('incidents', 'playbook_snapshot')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->json('playbook_snapshot')->nullable()->after('phase_timestamps');
            });
        }

        if (! Schema::hasColumn('incidents', 'governed_at')) {
            Schema::table('incidents', function (Blueprint $table) {
                $table->timestamp('governed_at')->nullable()->after('playbook_snapshot');
            });
        }

        if (! Schema::hasTable('incident_phase_transitions')) {
            Schema::create('incident_phase_transitions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('incident_id')->constrained()->restrictOnDelete();
                $table->string('from_phase')->nullable();
                $table->string('to_phase');
                $table->text('summary');
                $table->json('incident_snapshot');
                $table->foreignId('transitioned_by')->constrained('users')->restrictOnDelete();
                $table->timestamp('transitioned_at');
                $table->char('fingerprint', 64)->unique();
                $table->timestamps();
                $table->index(['incident_id', 'id']);
            });
        }

        $this->backfillSequences();
    }

    public function down(): void
    {
        // Governed incident history and numbering evidence are retained on routine rollback.
    }

    private function backfillSequences(): void
    {
        $maximumByYear = [];
        DB::table('incidents')->orderBy('id')->pluck('number')->each(function (mixed $number) use (&$maximumByYear): void {
            if (! is_string($number) || preg_match('/^INC-(\d{4})-(\d+)$/', $number, $matches) !== 1) {
                return;
            }

            $year = (int) $matches[1];
            $maximumByYear[$year] = max($maximumByYear[$year] ?? 0, (int) $matches[2]);
        });

        foreach ($maximumByYear as $year => $maximum) {
            DB::table('incident_number_sequences')->insertOrIgnore([
                'year' => $year, 'last_number' => $maximum, 'updated_at' => now(), 'created_at' => now(),
            ]);
            DB::table('incident_number_sequences')
                ->where('year', $year)
                ->where('last_number', '<', $maximum)
                ->update(['last_number' => $maximum, 'updated_at' => now()]);
        }
    }
};
