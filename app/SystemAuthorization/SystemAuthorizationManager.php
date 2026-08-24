<?php

namespace App\SystemAuthorization;

use App\Enums\SystemAuthorizationDecision;
use App\Models\Application;
use App\Models\Control;
use App\Models\Risk;
use App\Models\SystemAuthorizationDecisionRecord;
use App\Models\SystemAuthorizationPackage;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SystemAuthorizationManager
{
    /** @param array<string,mixed> $data */
    public function submit(User $actor, Application $application, array $data): SystemAuthorizationPackage
    {
        Enterprise::assertEnabled('system_authorization');

        return DB::transaction(function () use ($actor, $application, $data): SystemAuthorizationPackage {
            $app = Application::query()->withTrashed()->lockForUpdate()->findOrFail($application->id);
            abort_unless($actor->can('create', SystemAuthorizationPackage::class) && $actor->can('view', $app), 403);
            $data = Validator::make($data, self::packageRules())->validate();
            abort_if($app->trashed(), 422, 'Deleted applications cannot receive an authorization package.');
            $packages = SystemAuthorizationPackage::query()->where('application_id', $app->id)->orderBy('id')->lockForUpdate()->get();
            if ($packages->count() >= 100) {
                throw ValidationException::withMessages(['application' => 'An application is limited to 100 authorization package versions.']);
            }
            $controls = Control::query()->whereIn('id', $data['control_ids'])->orderBy('id')->lockForUpdate()->get();
            $risks = Risk::query()->whereIn('id', $data['risk_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($controls->count() !== count($data['control_ids'])) {
                throw ValidationException::withMessages(['control_ids' => 'Every selected control must exist.']);
            }
            if ($risks->count() !== count($data['risk_ids'])) {
                throw ValidationException::withMessages(['risk_ids' => 'Every selected risk must exist.']);
            }
            abort_unless($controls->every(fn (Control $control): bool => $actor->can('view', $control)) && $risks->every(fn (Risk $risk): bool => $actor->can('view', $risk)), 403, 'The submitter must be authorized to view every selected governance record.');
            $at = now()->startOfSecond();
            $payload = ['application_id' => $app->id, 'version' => $packages->count() + 1, 'application_snapshot' => $this->applicationSnapshot($app), 'system_boundary' => $data['system_boundary'], 'impact_level' => $data['impact_level'], 'data_classifications' => array_values($data['data_classifications']), 'control_snapshot' => $controls->map(fn (Control $c) => $c->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability']))->values()->all(), 'risk_snapshot' => $risks->map(fn (Risk $r) => $r->only(['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk']))->values()->all(), 'open_findings' => array_values($data['open_findings']), 'monitoring_strategy' => $data['monitoring_strategy'], 'poam_reference' => $data['poam_reference'] ?? null, 'change_summary' => $data['change_summary'], 'submitted_by' => $actor->id, 'submitted_at' => $at->toIso8601String()];

            return SystemAuthorizationPackage::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['application.owner:id,name,email', 'submitter:id,name', 'latestDecision']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function decide(User $actor, SystemAuthorizationPackage $package, array $data): SystemAuthorizationDecisionRecord
    {
        Enterprise::assertEnabled('system_authorization');

        return DB::transaction(function () use ($actor, $package, $data): SystemAuthorizationDecisionRecord {
            $locked = SystemAuthorizationPackage::query()->lockForUpdate()->findOrFail($package->id);
            $app = Application::query()->withTrashed()->lockForUpdate()->findOrFail($locked->application_id);
            abort_unless($actor->can('decide', $locked), 403);
            $data = Validator::make($data, self::decisionRules())->validate();
            $packages = SystemAuthorizationPackage::query()->where('application_id', $app->id)->orderBy('id')->lockForUpdate()->get();
            if ($packages->last()?->id !== $locked->id) {
                throw ValidationException::withMessages(['package' => 'Only the latest authorization package may be decided.']);
            }
            abort_if(in_array($actor->id, [$locked->submitted_by, $app->owner_id], true), 403, 'The submitter and application owner cannot authorize their package.');
            $controlIds = collect($locked->control_snapshot)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $riskIds = collect($locked->risk_snapshot)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $controls = Control::query()->whereIn('id', $controlIds)->orderBy('id')->lockForUpdate()->get();
            $risks = Risk::query()->whereIn('id', $riskIds)->orderBy('id')->lockForUpdate()->get();
            $currentControls = $this->canonical($controls->map(fn (Control $c) => $c->only(['id', 'code', 'title', 'status', 'effectiveness', 'applicability']))->values()->all());
            $currentRisks = $this->canonical($risks->map(fn (Risk $r) => $r->only(['id', 'code', 'name', 'domain', 'status', 'inherent_risk', 'residual_risk']))->values()->all());
            if ($this->applicationSnapshot($app) !== $locked->application_snapshot || $currentControls !== $locked->control_snapshot || $currentRisks !== $locked->risk_snapshot) {
                throw ValidationException::withMessages(['package' => 'The application or selected governance context changed after submission. Submit a new package.']);
            }
            $decisions = SystemAuthorizationDecisionRecord::query()->where('system_authorization_package_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($decisions->count() >= 100) {
                throw ValidationException::withMessages(['package' => 'A package is limited to 100 authorization decisions.']);
            }
            $decision = SystemAuthorizationDecision::from($data['decision']);
            if ($decisions->isNotEmpty() && $decision !== SystemAuthorizationDecision::Revoked) {
                throw ValidationException::withMessages(['decision' => 'Only revocation may follow a terminal authorization decision.']);
            }
            if ($decision === SystemAuthorizationDecision::Revoked && ! in_array($decisions->last()?->decision, [SystemAuthorizationDecision::Authorized, SystemAuthorizationDecision::AuthorizedWithConditions], true)) {
                throw ValidationException::withMessages(['decision' => 'Only an active authorization may be revoked.']);
            }
            if ($decision === SystemAuthorizationDecision::AuthorizedWithConditions && $data['conditions'] === []) {
                throw ValidationException::withMessages(['conditions' => 'Conditional authorization requires at least one condition.']);
            }
            if (in_array($decision, [SystemAuthorizationDecision::Authorized, SystemAuthorizationDecision::AuthorizedWithConditions], true) && empty($data['valid_until'])) {
                throw ValidationException::withMessages(['valid_until' => 'An authorization expiry date is required.']);
            }
            $at = now()->startOfSecond();
            $snapshot = $this->packageSnapshot($locked);
            $payload = ['system_authorization_package_id' => $locked->id, 'version' => $decisions->count() + 1, 'package_snapshot' => $snapshot, 'decision' => $decision->value, 'conditions' => array_values($data['conditions']), 'rationale' => $data['rationale'], 'decided_by' => $actor->id, 'decided_at' => $at->toIso8601String(), 'valid_until' => empty($data['valid_until']) ? null : Carbon::parse($data['valid_until'])->toDateString()];

            return SystemAuthorizationDecisionRecord::query()->create($payload + ['fingerprint' => $this->fingerprint($payload)])->load(['authorizer:id,name', 'package']);
        }, 3);
    }

    public static function packageRules(): array
    {
        return ['system_boundary' => 'required|string|max:30000', 'impact_level' => ['required', Rule::in(['Low', 'Moderate', 'High'])], 'data_classifications' => 'required|array|min:1|max:50', 'data_classifications.*' => 'string|max:255|distinct', 'control_ids' => 'present|array|max:500', 'control_ids.*' => 'integer|distinct', 'risk_ids' => 'present|array|max:500', 'risk_ids.*' => 'integer|distinct', 'open_findings' => 'present|array|max:100', 'open_findings.*' => 'string|max:2000|distinct', 'monitoring_strategy' => 'required|string|max:30000', 'poam_reference' => 'nullable|string|max:2000', 'change_summary' => 'required|string|max:30000', 'version' => 'prohibited', 'application_snapshot' => 'prohibited', 'control_snapshot' => 'prohibited', 'risk_snapshot' => 'prohibited', 'submitted_by' => 'prohibited', 'submitted_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    public static function decisionRules(): array
    {
        return ['decision' => ['required', Rule::enum(SystemAuthorizationDecision::class)], 'conditions' => 'present|array|max:100', 'conditions.*' => 'string|max:2000|distinct', 'rationale' => 'required|string|max:30000', 'valid_until' => 'nullable|date|after:today', 'version' => 'prohibited', 'package_snapshot' => 'prohibited', 'decided_by' => 'prohibited', 'decided_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function applicationSnapshot(Application $app): array
    {
        $app->load(['owner:id,name,email', 'vendor:id,name']);

        return $this->canonical($app->only(['id', 'name', 'type', 'description', 'status', 'url', 'notes']) + ['owner' => $app->owner?->only(['id', 'name', 'email']), 'vendor' => $app->vendor?->only(['id', 'name'])]);
    }

    private function packageSnapshot(SystemAuthorizationPackage $p): array
    {
        return $this->canonical($p->only(['id', 'application_id', 'version', 'application_snapshot', 'system_boundary', 'impact_level', 'data_classifications', 'control_snapshot', 'risk_snapshot', 'open_findings', 'monitoring_strategy', 'poam_reference', 'change_summary', 'submitted_by', 'submitted_at', 'fingerprint']));
    }

    private function canonical(array $value): array
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private function fingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
