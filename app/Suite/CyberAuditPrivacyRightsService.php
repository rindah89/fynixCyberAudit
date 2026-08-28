<?php

namespace App\Suite;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CyberAuditPrivacyRightsService
{
    /** @return array<string, mixed> */
    public function fulfill(User $user, string $right, array $payload): array
    {
        $completedAt = now()->utc()->startOfSecond();
        if ($right === 'correction') {
            $name = trim((string) ($payload['name'] ?? ''));
            if ($name === '') {
                throw ValidationException::withMessages(['name' => ['A corrected name is required.']]);
            }
            $user->update(['name' => $name]);
        } elseif ($right === 'restriction') {
            $user->forceFill(['privacy_restricted_at' => $completedAt])->save();
        } elseif ($right === 'objection') {
            $user->forceFill(['processing_objection_at' => $completedAt])->save();
        } elseif ($right === 'deletion') {
            if ($user->isSuperAdmin() || $user->is_break_glass) {
                throw ValidationException::withMessages(['right' => ['Privileged emergency accounts require reassignment before anonymization.']]);
            }
            $subjectRef = app(CyberAuditPrivacyExportService::class)->subjectRef($user);
            DB::table('personal_access_tokens')->where(['tokenable_type' => User::class, 'tokenable_id' => $user->getKey()])->delete();
            DB::table('model_has_roles')->where(['model_type' => User::class, 'model_id' => $user->getKey()])->delete();
            DB::table('model_has_permissions')->where(['model_type' => User::class, 'model_id' => $user->getKey()])->delete();
            DB::table('users')->where('id', $user->getKey())->update([
                'name' => 'Erased user',
                'email' => "erased+{$subjectRef}@invalid.fynix",
                'email_verified_at' => null, 'password' => Hash::make(Str::random(64)),
                'remember_token' => null, 'is_sso' => false, 'is_break_glass' => false,
                'sso_subject' => null, 'sso_issuer' => null,
                'privacy_restricted_at' => $completedAt, 'processing_objection_at' => $completedAt,
                'privacy_erased_at' => $completedAt, 'deleted_at' => $completedAt, 'updated_at' => $completedAt,
            ]);
        } else {
            throw ValidationException::withMessages(['right' => ['Unsupported privacy right.']]);
        }

        return ['right' => $right, 'completed_at' => $completedAt->toAtomString(), 'source_action_completed' => true];
    }
}
