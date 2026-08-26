<?php

namespace App\ComplianceCases;

use App\Models\ComplianceCase;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\ComplianceCaseAccessGrantRevocation;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ComplianceCaseAccessGrantManager
{
    /** @param array{grantee_id:int,purpose:string,starts_at:string,ends_at:string} $data */
    public function grant(User $actor, ComplianceCase $case, array $data): ComplianceCaseAccessGrant
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseAccessGrant {
            $locked = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $locked), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $locked);
            $data = Validator::make($data, self::grantRules())->validate();
            $grantee = User::query()->whereNull('deleted_at')->lockForUpdate()->findOrFail($data['grantee_id']);
            $starts = Carbon::parse($data['starts_at'])->utc()->startOfSecond();
            $ends = Carbon::parse($data['ends_at'])->utc()->startOfSecond();
            if ($ends->lessThanOrEqualTo($starts)) {
                throw ValidationException::withMessages(['ends_at' => 'A grant must end after it starts.']);
            }
            $existing = ComplianceCaseAccessGrant::query()->where('compliance_case_id', $locked->id)->orderBy('version')->lockForUpdate()->get();
            if ($existing->count() >= 100) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 100 access grants.']);
            }
            $grantedAt = now()->startOfSecond();
            $grant = new ComplianceCaseAccessGrant([
                'compliance_case_id' => $locked->id, 'version' => $existing->count() + 1,
                'grantee_id' => $grantee->id, 'grantee_snapshot' => $grantee->only(['id', 'name', 'email']),
                'purpose' => trim($data['purpose']), 'starts_at' => $starts, 'ends_at' => $ends,
                'granted_by' => $actor->id, 'grantor_snapshot' => $actor->only(['id', 'name', 'email']),
                'granted_at' => $grantedAt,
            ]);
            $grant->fingerprint = hash('sha256', CanonicalJson::encode($this->payload($grant)));
            $grant->save();

            return $grant->load('revocation');
        }, 3);
    }

    /** @param array{summary:string} $data */
    public function revoke(User $actor, ComplianceCaseAccessGrant $grant, array $data): ComplianceCaseAccessGrantRevocation
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $grant, $data): ComplianceCaseAccessGrantRevocation {
            $case = ComplianceCase::query()->lockForUpdate()->findOrFail($grant->compliance_case_id);
            $locked = ComplianceCaseAccessGrant::query()->where('compliance_case_id', $case->id)->lockForUpdate()->findOrFail($grant->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $case), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $case);
            $data = Validator::make($data, ['summary' => 'required|string|max:30000'])->validate();
            if (ComplianceCaseAccessGrantRevocation::query()->where('compliance_case_access_grant_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['grant' => 'This grant has already been revoked.']);
            }
            $revokedAt = now()->startOfSecond();
            $revocation = new ComplianceCaseAccessGrantRevocation([
                'compliance_case_access_grant_id' => $locked->id, 'summary' => trim($data['summary']),
                'revoked_by' => $actor->id, 'revoker_snapshot' => $actor->only(['id', 'name', 'email']),
                'grant_snapshot' => ['id' => $locked->id, 'fingerprint' => $locked->fingerprint] + $this->payload($locked),
                'revoked_at' => $revokedAt,
            ]);
            $revocation->fingerprint = hash('sha256', CanonicalJson::encode($this->revocationPayload($revocation)));
            $revocation->save();

            return $revocation;
        }, 3);
    }

    public static function granteeCanView(User $user, ComplianceCase $case): bool
    {
        return self::activeGrantQuery($user)->where('compliance_case_id', $case->id)->exists();
    }

    public static function granteeHasAnyActiveGrant(User $user): bool
    {
        return self::activeGrantQuery($user)->exists();
    }

    public function history(User $actor, ComplianceCase $case, int $perPage): LengthAwarePaginator
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless($actor->can('view', $case), 403);

        return $case->accessGrants()->with(['grantee:id,name,email', 'grantor:id,name,email', 'revocation'])->paginate($perPage);
    }

    public static function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('Manage Compliance Cases') || $user->can('Read Compliance Cases')) {
            return $query;
        }

        return $query->where(function ($visible) use ($user): void {
            if ($user->can('Investigate Compliance Cases')) {
                $visible->where('assigned_to', $user->id);
            }
            $visible->orWhereHas('accessGrants', function ($grants) use ($user): void {
                $now = now();
                $grants->where('grantee_id', $user->id)->where('starts_at', '<=', $now)->where('ends_at', '>=', $now)
                    ->whereDoesntHave('revocation');
            });
        });
    }

    private static function activeGrantQuery(User $user): Builder
    {
        $now = now();

        return ComplianceCaseAccessGrant::query()->where('grantee_id', $user->id)
            ->where('starts_at', '<=', $now)->where('ends_at', '>=', $now)
            ->whereDoesntHave('revocation');
    }

    /** @return array<string,mixed> */
    public function payload(ComplianceCaseAccessGrant $grant): array
    {
        return [
            'compliance_case_id' => $grant->compliance_case_id, 'version' => $grant->version,
            'grantee_id' => $grant->grantee_id, 'grantee_snapshot' => $grant->grantee_snapshot,
            'purpose' => $grant->purpose, 'starts_at' => $grant->starts_at?->toIso8601String(),
            'ends_at' => $grant->ends_at?->toIso8601String(), 'granted_by' => $grant->granted_by,
            'grantor_snapshot' => $grant->grantor_snapshot, 'granted_at' => $grant->granted_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function revocationPayload(ComplianceCaseAccessGrantRevocation $revocation): array
    {
        return [
            'compliance_case_access_grant_id' => $revocation->compliance_case_access_grant_id,
            'summary' => $revocation->summary, 'revoked_by' => $revocation->revoked_by,
            'revoker_snapshot' => $revocation->revoker_snapshot, 'grant_snapshot' => $revocation->grant_snapshot,
            'revoked_at' => $revocation->revoked_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public static function grantRules(): array
    {
        return [
            'grantee_id' => 'required|integer|exists:users,id', 'purpose' => 'required|string|max:30000',
            'starts_at' => 'required|date', 'ends_at' => 'required|date',
            'id' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited',
            'granted_by' => 'prohibited', 'granted_at' => 'prohibited', 'grantee_snapshot' => 'prohibited',
            'grantor_snapshot' => 'prohibited',
        ];
    }
}
