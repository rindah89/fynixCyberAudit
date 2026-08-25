<?php

use App\Enums\ThirdPartyCollaborationTimeliness;
use App\Support\CanonicalJson;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'response_recorded_at')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->timestamp('response_recorded_at')->nullable());
        }
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'timeliness_status')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->string('timeliness_status', 20)->nullable());
        }
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'days_late')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->unsignedInteger('days_late')->nullable());
        }
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'timeliness_fingerprint')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->char('timeliness_fingerprint', 64)->nullable());
        }
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'calendar_timezone')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->string('calendar_timezone', 64)->nullable());
        }
        if (! Schema::hasColumn('third_party_collaboration_request_closures', 'fingerprint_version')) {
            Schema::table('third_party_collaboration_request_closures', fn (Blueprint $table) => $table->string('fingerprint_version', 40)->nullable());
        }

        DB::table('third_party_collaboration_request_closures')->orderBy('id')->eachById(function (object $row): void {
            $accepted = json_decode($row->accepted_event_snapshot, true, flags: JSON_THROW_ON_ERROR);
            $dueContext = json_decode($row->due_context, true, flags: JSON_THROW_ON_ERROR);
            $responseAt = Carbon::parse(data_get($accepted, 'response.recorded_at'))->utc()->startOfSecond();
            $dueDate = Carbon::createFromFormat('Y-m-d', $dueContext['due_at'], 'UTC')->startOfDay();
            $responseDate = $responseAt->copy()->startOfDay();
            $daysLate = $responseDate->greaterThan($dueDate) ? (int) $dueDate->diffInDays($responseDate) : 0;
            $status = $daysLate === 0 ? ThirdPartyCollaborationTimeliness::OnTime->value : ThirdPartyCollaborationTimeliness::Late->value;
            $fingerprintedDueContext = [
                'due_at' => $dueContext['due_at'], 'fingerprint' => $dueContext['fingerprint'],
                'extension_id' => $dueContext['extension_id'], 'decision_id' => $dueContext['decision_id'],
            ];
            $fingerprintPayload = [
                'accepted_event_id' => $row->accepted_event_id,
                'response_recorded_at' => $responseAt->toIso8601String(),
                'due_context' => $fingerprintedDueContext,
                'calendar_timezone' => 'UTC',
                'timeliness_status' => $status,
                'days_late' => $daysLate,
            ];
            DB::table('third_party_collaboration_request_closures')->where('id', $row->id)->update([
                'response_recorded_at' => $responseAt,
                'timeliness_status' => $status,
                'days_late' => $daysLate,
                'timeliness_fingerprint' => hash('sha256', CanonicalJson::encode($fingerprintPayload)),
                'calendar_timezone' => 'UTC',
                'fingerprint_version' => $row->fingerprint_version ?? 'closure/v1',
            ]);
        });
    }

    public function down(): void
    {
        // Derived closure evidence remains retained during routine code rollback.
    }
};
