<?php

namespace App\Esg;

use App\Enums\EsgMaterialityDecision;
use App\Enums\EsgPillar;
use App\Enums\EsgTopicStatus;
use App\Models\EsgMaterialityAssessment;
use App\Models\EsgMaterialTopic;
use App\Models\EsgMaterialTopicVersion;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EsgMaterialityManager
{
    /** @param array<string,mixed> $data */
    public function register(User $actor, array $data): EsgMaterialTopic
    {
        Enterprise::assertEnabled('esg_management');
        abort_unless($actor->can('create', EsgMaterialTopic::class), 403);
        $data = Validator::make($data, self::topicRules(true))->validate();

        return DB::transaction(function () use ($actor, $data): EsgMaterialTopic {
            DB::table('esg_material_topic_mutexes')->where('id', 1)->lockForUpdate()->first();
            $owner = $this->lockOwner($data['owner_id']);
            $at = now()->startOfSecond();
            $next = ((int) EsgMaterialTopic::query()->max('id')) + 1;
            $topic = EsgMaterialTopic::query()->create([...Arr::except($data, ['change_summary']), 'code' => 'ESG-'.$at->format('Y').'-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'status' => EsgTopicStatus::Draft, 'governed_at' => $at]);
            $this->appendVersion($topic, $actor, $data['change_summary'], 1, $at);

            return $topic->load(['owner:id,name,email', 'versions.actor:id,name']);
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function revise(User $actor, EsgMaterialTopic $topic, array $data): EsgMaterialTopicVersion
    {
        Enterprise::assertEnabled('esg_management');

        return DB::transaction(function () use ($actor, $topic, $data): EsgMaterialTopicVersion {
            $locked = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($topic->id);
            abort_unless($actor->can('update', $locked), 403);
            $data = Validator::make($data, self::topicRules(false))->validate();
            $versions = EsgMaterialTopicVersion::query()->where('esg_material_topic_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($versions->count() >= 100) {
                throw ValidationException::withMessages(['topic' => 'An ESG topic is limited to 100 versions.']);
            }
            if ($locked->status === EsgTopicStatus::Retired) {
                throw ValidationException::withMessages(['topic' => 'Retired ESG topics are terminal.']);
            }
            $this->lockOwner($data['owner_id'] ?? $locked->owner_id);
            $changes = Arr::except($data, ['change_summary']);
            $candidate = clone $locked;
            $candidate->fill($changes);
            $dirty = Arr::only($changes, array_keys($candidate->getDirty()));
            $requested = array_key_exists('status', $dirty) ? EsgTopicStatus::from($dirty['status']) : null;
            $material = Arr::except($dirty, ['status']);
            if ($requested !== null && $requested !== EsgTopicStatus::Retired) {
                throw ValidationException::withMessages(['status' => 'Materiality state is server-derived from assessment.']);
            }
            if ($requested === EsgTopicStatus::Retired && $material !== []) {
                throw ValidationException::withMessages(['status' => 'Retirement cannot rewrite material topic context.']);
            }
            $changes = $dirty;
            if ($material !== []) {
                $changes['status'] = EsgTopicStatus::ReviewRequired;
            }
            $before = $this->snapshot($locked);
            $locked->update($changes);
            if ($before === $this->snapshot($locked->refresh())) {
                throw ValidationException::withMessages(['topic' => 'A revision must change governed state.']);
            }

            return $this->appendVersion($locked, $actor, $data['change_summary'], $versions->count() + 1, now()->startOfSecond())->load('actor:id,name');
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function assess(User $actor, EsgMaterialTopic $topic, array $data): EsgMaterialityAssessment
    {
        Enterprise::assertEnabled('esg_management');

        return DB::transaction(function () use ($actor, $topic, $data): EsgMaterialityAssessment {
            $locked = EsgMaterialTopic::query()->lockForUpdate()->findOrFail($topic->id);
            abort_unless($actor->can('assess', $locked), 403);
            $data = Validator::make($data, self::assessmentRules())->validate();
            if ($locked->status === EsgTopicStatus::Retired) {
                throw ValidationException::withMessages(['topic' => 'Retired ESG topics are terminal.']);
            }
            $version = EsgMaterialTopicVersion::query()->where('esg_material_topic_id', $locked->id)->latest('version')->lockForUpdate()->firstOrFail();
            $assessments = EsgMaterialityAssessment::query()->where('esg_material_topic_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            if ($assessments->count() >= 100) {
                throw ValidationException::withMessages(['assessment' => 'An ESG topic is limited to 100 materiality assessments.']);
            }
            abort_if(in_array($actor->id, [$locked->owner_id, $version->recorded_by], true), 403, 'The topic owner and latest version author cannot assess that version.');
            $this->lockOwner($locked->owner_id);
            if ($this->materialSnapshot($version->topic_snapshot) !== $this->materialSnapshot($this->snapshot($locked))) {
                throw ValidationException::withMessages(['topic' => 'The latest retained topic version does not match current material state.']);
            }
            $at = now()->startOfSecond();
            $decision = EsgMaterialityDecision::from($data['decision']);
            $payload = ['esg_material_topic_id' => $locked->id, 'version' => $assessments->count() + 1, 'topic_version_id' => $version->id, 'topic_snapshot' => $version->topic_snapshot, 'impact_materiality' => $data['impact_materiality'], 'financial_materiality' => $data['financial_materiality'], 'stakeholder_evidence' => $data['stakeholder_evidence'], 'methodology' => $data['methodology'], 'decision' => $decision->value, 'decision_summary' => $data['decision_summary'], 'assessed_by' => $actor->id, 'assessed_at' => $at->toIso8601String(), 'next_review_at' => Carbon::parse($data['next_review_at'])->toDateString()];
            $assessment = $locked->assessments()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
            $locked->update(['status' => match ($decision) {
                EsgMaterialityDecision::Material => EsgTopicStatus::Material,
                EsgMaterialityDecision::NotMaterial => EsgTopicStatus::NotMaterial,
                EsgMaterialityDecision::ChangesRequired => EsgTopicStatus::ReviewRequired,
            }, 'next_review_at' => $payload['next_review_at']]);

            return $assessment->load(['assessor:id,name', 'topicVersion']);
        }, 3);
    }

    public static function topicRules(bool $creating): array
    {
        $p = fn (string $r): string => ($creating ? 'required' : 'sometimes').'|'.$r;
        $rules = ['name' => $p('string|max:255'), 'pillar' => [$creating ? 'required' : 'sometimes', Rule::enum(EsgPillar::class)], 'owner_id' => $p('integer|exists:users,id'), 'description' => $p('string|max:30000'), 'impact_context' => $p('string|max:30000'), 'risk_context' => $p('string|max:30000'), 'opportunity_context' => $p('string|max:30000'), 'stakeholder_groups' => $p('array|min:1|max:100'), 'stakeholder_groups.*' => 'string|max:255|distinct', 'framework_references' => $p('array|max:100'), 'framework_references.*' => 'string|max:255|distinct', 'organizational_boundary' => $p('string|max:30000'), 'source_reference' => 'sometimes|nullable|string|max:2000', 'next_review_at' => $p('date|after_or_equal:today'), 'change_summary' => 'required|string|max:30000', 'status' => ['sometimes', Rule::enum(EsgTopicStatus::class)], 'code' => 'prohibited', 'governed_at' => 'prohibited', 'version' => 'prohibited', 'fingerprint' => 'prohibited'];
        if ($creating) {
            $rules['status'] = 'prohibited';
        }

        return $rules;
    }

    public static function assessmentRules(): array
    {
        return ['impact_materiality' => 'required|integer|min:1|max:5', 'financial_materiality' => 'required|integer|min:1|max:5', 'stakeholder_evidence' => 'required|string|max:30000', 'methodology' => 'required|string|max:30000', 'decision' => ['required', Rule::enum(EsgMaterialityDecision::class)], 'decision_summary' => 'required|string|max:30000', 'next_review_at' => 'required|date|after:today', 'version' => 'prohibited', 'topic_snapshot' => 'prohibited', 'topic_version_id' => 'prohibited', 'assessed_by' => 'prohibited', 'assessed_at' => 'prohibited', 'fingerprint' => 'prohibited'];
    }

    private function lockOwner(int $id): User
    {
        $owner = User::query()->whereNull('deleted_at')->lockForUpdate()->find($id);
        if (! $owner || (! $owner->can('Own ESG Topics') && ! $owner->can('Manage ESG'))) {
            throw ValidationException::withMessages(['owner_id' => 'The ESG topic owner must be active and authorized.']);
        }

        return $owner;
    }

    private function appendVersion(EsgMaterialTopic $topic, User $actor, string $summary, int $version, $at): EsgMaterialTopicVersion
    {
        $payload = ['esg_material_topic_id' => $topic->id, 'version' => $version, 'topic_snapshot' => $this->snapshot($topic->refresh()), 'change_summary' => $summary, 'recorded_by' => $actor->id, 'recorded_at' => $at->toIso8601String()];

        return $topic->versions()->create($payload + ['fingerprint' => $this->fingerprint($payload)]);
    }

    private function snapshot(EsgMaterialTopic $topic): array
    {
        $topic->load('owner:id,name,email');
        $s = $topic->only(['id', 'code', 'name', 'pillar', 'status', 'description', 'impact_context', 'risk_context', 'opportunity_context', 'stakeholder_groups', 'framework_references', 'organizational_boundary', 'source_reference', 'next_review_at', 'governed_at']) + ['owner' => $topic->owner?->only(['id', 'name', 'email'])];

        return json_decode(json_encode($s, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), true, flags: JSON_THROW_ON_ERROR);
    }

    private function materialSnapshot(array $snapshot): array
    {
        return Arr::except($snapshot, ['status', 'next_review_at']);
    }

    private function fingerprint(array $p): string
    {
        return hash('sha256', json_encode($p, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
