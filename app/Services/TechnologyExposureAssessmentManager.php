<?php

namespace App\Services;

use App\Enums\Applicability;
use App\Enums\ImplementationStatus;
use App\Enums\RiskDomain;
use App\Enums\TechnologyExposureState;
use App\Enums\TechnologyExposureType;
use App\Models\Asset;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use App\Models\TechnologyExposureAssessment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnologyExposureAssessmentManager
{
    /** @param array<string, mixed> $data */
    public function assess(Risk $risk, User $actor, array $data): TechnologyExposureAssessment
    {
        return DB::transaction(function () use ($risk, $actor, $data): TechnologyExposureAssessment {
            app(RiskPortfolioContextManager::class)->lockSnapshotBoundary();
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            if (! $actor->can('Manage Risk Portfolio')) {
                abort(403, 'You cannot record technology exposure assessments.');
            }
            $data = $this->validateInput($data);
            $profile = RiskGovernanceProfile::query()->where('risk_id', $locked->id)->lockForUpdate()->first();
            if (! $profile || $locked->domain !== RiskDomain::Technology || ! $locked->is_active) {
                throw ValidationException::withMessages(['risk_id' => 'Assessments require an active governed technology risk.']);
            }
            app(RiskPortfolioManager::class)->lockContextGraph($locked, $profile);
            $asset = Asset::query()->whereKey($data['asset_id'])->where('is_active', true)->lockForUpdate()->first();
            if (! $asset || ! DB::table('asset_risk')->where('risk_id', $locked->id)->where('asset_id', $asset->id)->exists()) {
                throw ValidationException::withMessages(['asset_id' => 'Select an active asset currently mapped to this technology risk.']);
            }
            if (! $locked->implementations()->where('implementations.status', ImplementationStatus::FULL)
                ->whereHas('controls', fn ($query) => $query->where('controls.applicability', Applicability::APPLICABLE))->exists()) {
                throw ValidationException::withMessages(['implementations' => 'Technology exposure assessment requires a fully implemented implementation linked to an applicable control.']);
            }

            $inherentScore = $data['inherent_likelihood'] * $data['inherent_impact'];
            $residualScore = $data['residual_likelihood'] * $data['residual_impact'];
            if ($residualScore > $inherentScore) {
                throw ValidationException::withMessages(['residual_likelihood' => 'Residual exposure score cannot exceed inherent exposure score.']);
            }
            $snapshot = $locked->portfolioGovernanceSnapshot($profile);
            $assetSnapshot = collect($snapshot['assets'])->firstWhere('id', $asset->id);
            if (! $assetSnapshot) {
                throw ValidationException::withMessages(['asset_id' => 'The selected mapped asset could not be snapshotted.']);
            }
            $version = (int) $locked->technologyExposureAssessments()->max('version') + 1;
            $assessedAt = now();

            return $locked->technologyExposureAssessments()->create([
                'version' => $version,
                'asset_id_snapshot' => $asset->id,
                'assessed_by' => $actor->id,
                'exposure_type' => $data['exposure_type'],
                'title' => $data['title'],
                'threat_scenario' => $data['threat_scenario'],
                'vulnerability_reference' => $data['vulnerability_reference'] ?? null,
                'vulnerability_description' => $data['vulnerability_description'],
                'source_reference' => $data['source_reference'] ?? null,
                'inherent_likelihood' => $data['inherent_likelihood'],
                'inherent_impact' => $data['inherent_impact'],
                'inherent_score' => $inherentScore,
                'residual_likelihood' => $data['residual_likelihood'],
                'residual_impact' => $data['residual_impact'],
                'residual_score' => $residualScore,
                'appetite_threshold_snapshot' => $profile->appetite_threshold,
                'state' => $residualScore > $profile->appetite_threshold ? TechnologyExposureState::AboveAppetite : TechnologyExposureState::WithinAppetite,
                'recommended_response' => $data['recommended_response'],
                'review_due_at' => $data['review_due_at'],
                'asset_snapshot' => $assetSnapshot,
                'governance_snapshot' => $snapshot,
                'governance_fingerprint' => $snapshot['fingerprint'],
                'assessed_at' => $assessedAt,
            ])->load(['asset:id,asset_tag,name', 'assessor:id,name']);
        }, 3);
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function validateInput(array $data): array
    {
        return Validator::make($data, [
            'asset_id' => ['required', 'integer', 'exists:assets,id'], 'exposure_type' => ['required', Rule::enum(TechnologyExposureType::class)],
            'title' => ['required', 'string', 'max:255'], 'threat_scenario' => ['required', 'string', 'max:30000'],
            'vulnerability_reference' => ['nullable', 'string', 'max:255'], 'vulnerability_description' => ['required', 'string', 'max:30000'],
            'source_reference' => ['nullable', 'string', 'max:255'], 'inherent_likelihood' => ['required', 'integer', 'between:1,5'],
            'inherent_impact' => ['required', 'integer', 'between:1,5'], 'residual_likelihood' => ['required', 'integer', 'between:1,5'],
            'residual_impact' => ['required', 'integer', 'between:1,5'], 'recommended_response' => ['required', 'string', 'max:30000'],
            'review_due_at' => ['required', 'date', 'after_or_equal:today'],
        ])->validate();
    }
}
