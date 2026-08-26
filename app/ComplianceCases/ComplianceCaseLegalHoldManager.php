<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseEvent;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseLegalHoldAcknowledgement;
use App\Models\ComplianceCaseLegalHoldCustodian;
use App\Models\ComplianceCaseLegalHoldRelease;
use App\Models\User;
use App\Support\CanonicalJson;
use App\Support\Enterprise;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ComplianceCaseLegalHoldManager
{
    /** @param array<string,mixed> $data */
    public function issue(User $actor, ComplianceCase $case, array $data): ComplianceCaseLegalHold
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $data): ComplianceCaseLegalHold {
            $lockedCase = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('update', $lockedCase), 403);
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $lockedCase);
            $data = Validator::make($data, self::issueRules())->validate();
            $data['scope'] = trim($data['scope']);
            if ($data['scope'] === '') {
                throw ValidationException::withMessages(['scope' => 'A named preservation scope is required.']);
            }
            $data['legal_basis_reference'] = isset($data['legal_basis_reference']) && trim($data['legal_basis_reference']) !== ''
                ? trim($data['legal_basis_reference']) : null;
            if ($lockedCase->status === ComplianceCaseStatus::Closed) {
                throw ValidationException::withMessages(['case' => 'Closed compliance cases cannot receive new legal holds.']);
            }
            $event = ComplianceCaseEvent::query()->where('compliance_case_id', $lockedCase->id)->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $holds = ComplianceCaseLegalHold::query()->where('compliance_case_id', $lockedCase->id)->orderBy('id')->lockForUpdate()->get();
            if ($holds->count() >= 20) {
                throw ValidationException::withMessages(['case' => 'A governed compliance case is limited to 20 legal holds.']);
            }
            $issuedAt = now();
            $preservationStart = Carbon::parse($data['preservation_start_at']);
            if ($preservationStart->isAfter($issuedAt)) {
                throw ValidationException::withMessages(['preservation_start_at' => 'A preservation instruction cannot start in the future.']);
            }
            $users = User::query()->whereNull('deleted_at')->whereIn('id', $data['custodian_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($users->count() !== count(array_unique($data['custodian_ids']))) {
                throw ValidationException::withMessages(['custodian_ids' => 'Every custodian must be a current active user.']);
            }
            $systems = $this->canonicalStrings($data['systems']);
            $dataCategories = $this->canonicalStrings($data['data_categories']);
            if ($systems === []) {
                throw ValidationException::withMessages(['systems' => 'At least one named system is required.']);
            }
            if ($dataCategories === []) {
                throw ValidationException::withMessages(['data_categories' => 'At least one named data category is required.']);
            }
            $version = $holds->count() + 1;
            $custodians = $users->map(fn (User $user): array => $this->userSnapshot($user))->values()->all();
            $payload = [
                'compliance_case_id' => $lockedCase->id, 'compliance_case_event_id' => $event->id,
                'version' => $version, 'reference' => $lockedCase->number.'-LH-'.str_pad((string) $version, 2, '0', STR_PAD_LEFT),
                'scope' => $data['scope'], 'systems' => $systems, 'data_categories' => $dataCategories,
                'legal_basis_reference' => $data['legal_basis_reference'] ?? null,
                'preservation_start_at' => $preservationStart->toIso8601String(),
                'issued_by' => $actor->id, 'issuer_snapshot' => $this->userSnapshot($actor),
                'case_snapshot' => $this->caseSnapshot($lockedCase), 'latest_event_snapshot' => $this->eventSnapshot($event),
                'custodian_snapshot' => $custodians, 'issued_at' => $issuedAt->toIso8601String(),
            ];
            $hold = ComplianceCaseLegalHold::query()->create($payload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
            ]);
            foreach ($users as $user) {
                $hold->custodians()->create(['user_id' => $user->id, 'recipient_snapshot' => $this->userSnapshot($user)]);
            }

            return $hold->load($this->relations());
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function acknowledge(User $actor, ComplianceCaseLegalHold $hold, array $data): ComplianceCaseLegalHoldAcknowledgement
    {
        Enterprise::assertEnabled('compliance_cases');
        $caseId = $hold->compliance_case_id;

        return DB::transaction(function () use ($actor, $hold, $data, $caseId): ComplianceCaseLegalHoldAcknowledgement {
            ComplianceCase::query()->lockForUpdate()->findOrFail($caseId);
            $locked = ComplianceCaseLegalHold::query()->lockForUpdate()->findOrFail($hold->id);
            $custodian = ComplianceCaseLegalHoldCustodian::query()->where('compliance_case_legal_hold_id', $locked->id)
                ->where('user_id', $actor->id)->lockForUpdate()->first();
            $activeActor = User::query()->whereNull('deleted_at')->lockForUpdate()->find($actor->id);
            abort_unless($custodian !== null && $activeActor !== null, 403);
            $data = Validator::make($data, self::acknowledgementRules())->validate();
            $data['statement'] = trim($data['statement']);
            if ($data['statement'] === '') {
                throw ValidationException::withMessages(['statement' => 'An acknowledgement statement is required.']);
            }
            $data['comment'] = isset($data['comment']) && trim($data['comment']) !== '' ? trim($data['comment']) : null;
            $acknowledgement = ComplianceCaseLegalHoldAcknowledgement::query()
                ->where('compliance_case_legal_hold_custodian_id', $custodian->id)->lockForUpdate()->first();
            $release = ComplianceCaseLegalHoldRelease::query()->where('compliance_case_legal_hold_id', $locked->id)->lockForUpdate()->first();
            if ($release !== null) {
                throw ValidationException::withMessages(['hold' => 'Released legal holds cannot be acknowledged.']);
            }
            if ($acknowledgement !== null) {
                throw ValidationException::withMessages(['hold' => 'This legal-hold notice has already been acknowledged.']);
            }
            $acknowledgedAt = now();
            $payload = [
                'compliance_case_legal_hold_id' => $locked->id,
                'compliance_case_legal_hold_custodian_id' => $custodian->id, 'user_id' => $actor->id,
                'hold_snapshot' => $this->holdSnapshot($locked), 'recipient_snapshot' => $this->userSnapshot($activeActor),
                'statement' => $data['statement'], 'comment' => $data['comment'] ?? null,
                'acknowledged_at' => $acknowledgedAt->toIso8601String(),
            ];

            return ComplianceCaseLegalHoldAcknowledgement::query()->create($payload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
            ])->load('user:id,name,email');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function release(User $actor, ComplianceCase $case, ComplianceCaseLegalHold $hold, array $data): ComplianceCaseLegalHoldRelease
    {
        Enterprise::assertEnabled('compliance_cases');

        return DB::transaction(function () use ($actor, $case, $hold, $data): ComplianceCaseLegalHoldRelease {
            $lockedCase = ComplianceCase::query()->lockForUpdate()->findOrFail($case->id);
            $locked = ComplianceCaseLegalHold::query()->where('compliance_case_id', $lockedCase->id)->lockForUpdate()->findOrFail($hold->id);
            abort_unless($actor->can('Manage Compliance Cases') && $actor->can('view', $lockedCase), 403);
            abort_if($actor->id === $locked->issued_by, 403, 'The legal-hold issuer cannot release the same hold.');
            app(ComplianceCaseConflictManager::class)->assertClear($actor, $lockedCase);
            $data = Validator::make($data, self::releaseRules())->validate();
            $data['summary'] = trim($data['summary']);
            if ($data['summary'] === '') {
                throw ValidationException::withMessages(['summary' => 'A release summary is required.']);
            }
            $custodians = ComplianceCaseLegalHoldCustodian::query()->where('compliance_case_legal_hold_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $activeUserIds = User::query()->whereNull('deleted_at')->whereIn('id', $custodians->pluck('user_id'))->orderBy('id')->lockForUpdate()->pluck('id');
            $acknowledgements = ComplianceCaseLegalHoldAcknowledgement::query()->where('compliance_case_legal_hold_id', $locked->id)->orderBy('id')->lockForUpdate()->get()->keyBy('compliance_case_legal_hold_custodian_id');
            if (ComplianceCaseLegalHoldRelease::query()->where('compliance_case_legal_hold_id', $locked->id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['hold' => 'A legal hold can be released only once.']);
            }
            if ($custodians->whereIn('user_id', $activeUserIds)->contains(fn (ComplianceCaseLegalHoldCustodian $custodian): bool => ! $acknowledgements->has($custodian->id))) {
                throw ValidationException::withMessages(['hold' => 'Every current active custodian must acknowledge the notice before release.']);
            }
            $releasedAt = now();
            $custodianSnapshot = $custodians->map(fn (ComplianceCaseLegalHoldCustodian $custodian): array => [
                'id' => $custodian->id, 'user_id' => $custodian->user_id, 'recipient_snapshot' => $custodian->recipient_snapshot,
                'active_at_release' => $activeUserIds->contains($custodian->user_id),
                'acknowledgement' => ($acknowledgement = $acknowledgements->get($custodian->id))
                    ? $this->acknowledgementSnapshot($acknowledgement) : null,
            ])->values()->all();
            $payload = [
                'compliance_case_legal_hold_id' => $locked->id, 'released_by' => $actor->id,
                'actor_snapshot' => $this->userSnapshot($actor), 'hold_snapshot' => $this->holdSnapshot($locked),
                'custodian_acknowledgement_snapshot' => $custodianSnapshot, 'summary' => $data['summary'],
                'released_at' => $releasedAt->toIso8601String(),
            ];

            return ComplianceCaseLegalHoldRelease::query()->create($payload + [
                'fingerprint' => hash('sha256', CanonicalJson::encode($payload)),
            ])->load('actor:id,name,email');
        }, 3);
    }

    /** @return array<string,mixed> */
    public static function issueRules(): array
    {
        return [
            'scope' => 'required|string|max:30000', 'systems' => 'required|array|min:1|max:50',
            'systems.*' => 'required|string|max:255', 'data_categories' => 'required|array|min:1|max:50',
            'data_categories.*' => 'required|string|max:255', 'legal_basis_reference' => 'nullable|string|max:1000',
            'preservation_start_at' => 'required|date', 'custodian_ids' => 'required|array|min:1|max:100',
            'custodian_ids.*' => 'required|integer|distinct', 'reference' => 'prohibited', 'version' => 'prohibited',
            'compliance_case_id' => 'prohibited', 'compliance_case_event_id' => 'prohibited', 'issued_by' => 'prohibited',
            'issuer_snapshot' => 'prohibited', 'case_snapshot' => 'prohibited', 'latest_event_snapshot' => 'prohibited',
            'custodian_snapshot' => 'prohibited',
            'issued_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function acknowledgementRules(): array
    {
        return [
            'statement' => 'required|string|max:1000', 'comment' => 'nullable|string|max:10000',
            'compliance_case_legal_hold_id' => 'prohibited', 'compliance_case_legal_hold_custodian_id' => 'prohibited',
            'user_id' => 'prohibited', 'hold_snapshot' => 'prohibited', 'recipient_snapshot' => 'prohibited',
            'acknowledged_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @return array<string,mixed> */
    public static function releaseRules(): array
    {
        return [
            'summary' => 'required|string|max:30000', 'compliance_case_legal_hold_id' => 'prohibited',
            'released_by' => 'prohibited', 'actor_snapshot' => 'prohibited', 'hold_snapshot' => 'prohibited',
            'custodian_acknowledgement_snapshot' => 'prohibited', 'released_at' => 'prohibited', 'fingerprint' => 'prohibited',
        ];
    }

    /** @return list<string> */
    public function relations(): array
    {
        return ['issuer:id,name,email', 'custodians.user:id,name,email', 'custodians.acknowledgement.user:id,name,email', 'release.actor:id,name,email'];
    }

    /** @return array<string,mixed> */
    public function holdSnapshot(ComplianceCaseLegalHold $hold): array
    {
        $hold->loadMissing(['custodians.user:id,name,email']);

        return $hold->only([
            'id', 'compliance_case_id', 'compliance_case_event_id', 'version', 'reference', 'scope', 'systems',
            'data_categories', 'legal_basis_reference', 'preservation_start_at', 'issued_by', 'issuer_snapshot',
            'custodian_snapshot', 'case_snapshot', 'latest_event_snapshot', 'issued_at', 'fingerprint',
        ]) + ['custodians' => $hold->custodians->map(fn (ComplianceCaseLegalHoldCustodian $custodian): array => [
            'id' => $custodian->id, 'user_id' => $custodian->user_id, 'recipient_snapshot' => $custodian->recipient_snapshot,
        ])->values()->all()];
    }

    /** @return array<string,mixed> */
    public function acknowledgementSnapshot(ComplianceCaseLegalHoldAcknowledgement $acknowledgement): array
    {
        return $acknowledgement->only([
            'id', 'compliance_case_legal_hold_id', 'compliance_case_legal_hold_custodian_id', 'user_id',
            'recipient_snapshot', 'statement', 'comment', 'fingerprint',
        ]) + ['acknowledged_at' => $acknowledgement->acknowledged_at->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function caseSnapshot(ComplianceCase $case): array
    {
        $case->load(['opener:id,name,email', 'assignee:id,name,email']);

        return $case->only([
            'id', 'number', 'title', 'category', 'priority', 'status', 'allegation', 'source_channel', 'source_reference',
            'reporter_reference', 'confidential', 'due_at', 'triage_summary', 'investigation_summary', 'resolution_summary',
            'closure_summary', 'opened_at', 'resolved_at', 'closed_at', 'governed_at',
        ]) + ['opened_by' => $case->opener?->only(['id', 'name', 'email']), 'assigned_to' => $case->assignee?->only(['id', 'name', 'email'])];
    }

    /** @return array<string,mixed> */
    private function eventSnapshot(ComplianceCaseEvent $event): array
    {
        return $event->only(['id', 'compliance_case_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot', 'summary', 'recorded_by', 'fingerprint'])
            + ['recorded_at' => $event->recorded_at->toIso8601String()];
    }

    /** @return array<string,mixed> */
    private function userSnapshot(User $user): array
    {
        return $user->only(['id', 'name', 'email']) + ['active' => $user->deleted_at === null];
    }

    /** @param list<string> $values @return list<string> */
    private function canonicalStrings(array $values): array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', $values), fn (string $value): bool => $value !== '')));
        sort($values, SORT_STRING);

        return $values;
    }
}
