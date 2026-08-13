<?php

namespace App\Access;

use App\Models\Survey;
use App\Models\VendorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;

class VendorAccess
{
    public const SESSION_KEY = 'vendor_access_claim';

    public function mayOpenSurvey(?VendorUser $user, Survey $survey): bool
    {
        if (! $user) {
            return false;
        }

        if ($survey->vendor_id && (int) $survey->vendor_id === (int) $user->vendor_id) {
            return true;
        }

        return filled($survey->respondent_email)
            && strcasecmp((string) $survey->respondent_email, (string) $user->email) === 0;
    }

    public function applySurveyScope(Builder $query, VendorUser $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('respondent_email', $user->email)
                ->orWhere('vendor_id', $user->vendor_id);
        });
    }

    public function grantSurveyClaim(Survey $survey, ?string $email = null): void
    {
        session([
            self::SESSION_KEY => [
                'survey_id' => $survey->id,
                'email' => $email ?? $survey->respondent_email,
            ],
        ]);
    }

    public function claim(Survey $survey): ?array
    {
        $claim = session(self::SESSION_KEY);

        if (! is_array($claim) || (int) ($claim['survey_id'] ?? 0) !== (int) $survey->id) {
            return null;
        }

        return $claim;
    }

    public function hasSurveyClaim(Survey $survey): bool
    {
        return $this->claim($survey) !== null;
    }

    public function requireSurveyClaim(Survey $survey): array
    {
        $claim = $this->claim($survey);

        if ($claim === null) {
            abort(401, 'This link has expired or is invalid.');
        }

        return $claim;
    }

    public function establishClaimFromRequest(Request $request, Survey $survey): void
    {
        if ($request->hasValidSignature()) {
            $this->grantSurveyClaim($survey, $request->query('email', $survey->respondent_email));

            return;
        }

        $this->requireSurveyClaim($survey);
    }

    public function surveyAccessUrl(Survey $survey, ?string $email = null): string
    {
        $expiryHours = (int) setting('vendor_portal.magic_link_expiry_hours', 48);

        return URL::temporarySignedRoute(
            'filament.vendor.pages.survey-access',
            now()->addHours(max($expiryHours, 1)),
            [
                'survey' => $survey->id,
                'email' => $email ?? $survey->respondent_email,
            ]
        );
    }

    public function register(Survey $survey, string $name, string $email, string $password): VendorUser
    {
        $claim = $this->requireSurveyClaim($survey);
        $this->assertClaimEmail($claim, $email);

        if (! $survey->vendor_id) {
            abort(404, 'Survey not found.');
        }

        if (VendorUser::where('email', $email)->exists()) {
            abort(409, 'This email is already registered.');
        }

        return VendorUser::create([
            'vendor_id' => $survey->vendor_id,
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'email_verified_at' => now(),
        ]);
    }

    public function setPassword(Survey $survey, VendorUser $user, string $password): VendorUser
    {
        $claim = $this->requireSurveyClaim($survey);
        $this->assertClaimEmail($claim, $user->email);

        if ($user->trashed()) {
            abort(403, 'This account has been deactivated.');
        }

        $user->update(['password' => $password]);

        return $user->refresh();
    }

    public function login(VendorUser $user, Survey $survey): void
    {
        if ($user->trashed()) {
            abort(403, 'This account has been deactivated.');
        }

        if (! $this->mayOpenSurvey($user, $survey)) {
            abort(403, 'You do not have permission to access this survey.');
        }

        Auth::guard('vendor')->login($user);
        $user->update(['last_login_at' => now()]);
        session()->regenerate();
    }

    /**
     * @param  array{survey_id?: int, email?: string|null}  $claim
     */
    protected function assertClaimEmail(array $claim, string $email): void
    {
        $claimed = $claim['email'] ?? null;

        if (filled($claimed) && strcasecmp((string) $claimed, $email) !== 0) {
            abort(403, 'This link is not valid for this account.');
        }
    }
}
