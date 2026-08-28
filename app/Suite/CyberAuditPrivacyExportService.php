<?php

namespace App\Suite;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class CyberAuditPrivacyExportService
{
    /** @var array<string, list<string>> */
    private const USER_LINKS = [
        'audit_items' => ['user_id'],
        'assets' => ['assigned_to_user_id', 'created_by'],
        'policies' => ['owner_id', 'created_by'],
        'controls' => ['control_owner_id'],
        'applications' => ['owner_id'],
        'risk_assessments' => ['owner_id'],
        'risk_assessment_collaborators' => ['user_id'],
        'remediation_tasks' => ['owner_id'],
        'remediation_projects' => ['owner_id'],
        'remediation_project_members' => ['user_id'],
        'data_requests' => ['created_by_id', 'assigned_to_id'],
        'survey_templates' => ['created_by_id'],
        'surveys' => ['created_by_id', 'assigned_to_id', 'approver_id'],
        'implementations' => ['implementation_owner_id'],
        'programs' => ['program_manager_id'],
        'policy_exceptions' => ['created_by'],
        'ai_jobs' => ['created_by'],
        'privacy_requests' => ['reviewed_by'],
        'disposition_receipts' => ['reviewed_by'],
        'data_processors' => ['reviewed_by'],
        'recovery_evidence' => ['reviewed_by'],
        'governance_control_reviews' => ['reviewer_id'],
        'processor_register_certifications' => ['reviewer_id'],
    ];

    /** @return array<string, mixed> */
    public function export(User $user): array
    {
        $missing = collect(self::USER_LINKS)->filter(function (array $columns, string $table): bool {
            return ! Schema::hasTable($table) || collect($columns)->contains(fn (string $column): bool => ! Schema::hasColumn($table, $column));
        })->keys()->all();
        if ($missing !== []) {
            throw new RuntimeException('Privacy export registry is incomplete: '.implode(', ', $missing));
        }

        $links = [];
        foreach (self::USER_LINKS as $table => $columns) {
            $available = Schema::getColumnListing($table);
            $selected = array_values(array_intersect(['id', ...$columns, 'status', 'created_at', 'updated_at', 'decided_at', 'reviewed_at'], $available));
            $query = DB::table($table)->select($selected)->where(function ($query) use ($columns, $user): void {
                foreach ($columns as $index => $column) {
                    $index === 0 ? $query->where($column, $user->getKey()) : $query->orWhere($column, $user->getKey());
                }
            });
            $links[$table] = $query->orderBy($selected[0])->get()->map(fn ($row): array => (array) $row)->all();
        }

        $emailLinks = [];
        foreach (['approvals' => 'approver_email', 'surveys' => 'respondent_email', 'trust_center_access_requests' => 'requester_email'] as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                throw new RuntimeException("Privacy export registry is incomplete: {$table}");
            }
            $available = Schema::getColumnListing($table);
            $selected = array_values(array_intersect(['id', $column, 'status', 'created_at', 'updated_at'], $available));
            $emailLinks[$table] = DB::table($table)->select($selected)->whereRaw('lower('.$column.') = ?', [Str::lower($user->email)])->orderBy($selected[0])->get()->map(fn ($row): array => (array) $row)->all();
        }

        $subjectRef = $this->subjectRef($user);
        $export = [
            'schema_version' => 'fynix-cyberaudit-person-export/v1',
            'subject_ref' => $subjectRef,
            'exported_at' => now()->utc()->toAtomString(),
            'account' => [
                'name' => $user->name, 'email' => $user->email,
                'email_verified_at' => $user->email_verified_at?->utc()->toAtomString(),
                'last_activity' => $user->last_activity?->utc()->toAtomString(),
                'is_sso' => (bool) $user->is_sso, 'is_break_glass' => (bool) $user->is_break_glass,
                'created_at' => $user->created_at?->utc()->toAtomString(),
                'roles' => $user->getRoleNames()->sort()->values()->all(),
            ],
            'relationship_manifest' => array_keys(self::USER_LINKS),
            'relationships' => $links,
            'email_relationships' => $emailLinks,
        ];
        $export['evidence_sha256'] = hash('sha256', json_encode($export, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $export;
    }

    public function subjectRef(User $user): string
    {
        $hex = substr(hash('sha256', 'cyberaudit-person'.chr(31).$user->getKey()), 0, 32);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 3) | 8);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
