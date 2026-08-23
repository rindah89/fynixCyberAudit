<?php

namespace App\Services;

use App\Enums\OperationalLossEventCategory;
use App\Enums\RiskDomain;
use App\Models\BusinessService;
use App\Models\OperationalLossEvent;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalLossEventManager
{
    public function record(Risk $risk, User $actor, array $data): OperationalLossEvent
    {
        return DB::transaction(function () use ($risk, $actor, $data): OperationalLossEvent {
            $locked = Risk::query()->lockForUpdate()->findOrFail($risk->id);
            if (! $actor->can('Manage Risk Portfolio')) {
                abort(403, 'You cannot record operational loss events.');
            }
            $validated = Validator::make($data, self::rules())->validate();
            if ($locked->domain !== RiskDomain::Operational) {
                throw ValidationException::withMessages(['risk' => 'Operational loss events require a risk classified as operational.']);
            }
            $profile = $locked->governanceProfile()->lockForUpdate()->first();
            if (! $profile || ! $profile->business_service_id) {
                throw ValidationException::withMessages(['business_service' => 'A governed operational risk with a mapped business service is required.']);
            }
            $service = BusinessService::query()->whereKey($profile->business_service_id)->lockForUpdate()->first();
            if (! $service || $service->status !== 'active') {
                throw ValidationException::withMessages(['business_service' => 'The mapped business service must be active when the loss event is recorded.']);
            }

            $grossCents = $this->cents($validated['gross_loss']);
            $recoveryCents = $this->cents($validated['recoveries'] ?? '0');
            if ($recoveryCents > $grossCents) {
                throw ValidationException::withMessages(['recoveries' => 'Recoveries cannot exceed the gross loss for this event.']);
            }
            $recordedAt = now();

            return $locked->operationalLossEvents()->create([
                'business_service_id_snapshot' => $service->id,
                'business_service_snapshot' => $service->only(['id', 'owner_id', 'code', 'name', 'criticality', 'status']),
                'reported_by' => $actor->id,
                'category' => $validated['category'],
                'occurred_at' => $validated['occurred_at'],
                'detected_at' => $validated['detected_at'],
                'summary' => $validated['summary'],
                'gross_loss' => $this->amount($grossCents),
                'recoveries' => $this->amount($recoveryCents),
                'net_loss' => $this->amount($grossCents - $recoveryCents),
                'currency' => $validated['currency'],
                'source_reference' => $validated['source_reference'] ?? null,
                'recorded_at' => $recordedAt,
            ])->load(['reporter:id,name', 'businessService:id,code,name']);
        }, 3);
    }

    public static function rules(): array
    {
        return [
            'category' => ['required', Rule::enum(OperationalLossEventCategory::class)],
            'occurred_at' => ['required', 'date', 'before_or_equal:today'],
            'detected_at' => ['required', 'date', 'after_or_equal:occurred_at', 'before_or_equal:today'],
            'summary' => ['required', 'string', 'max:30000'],
            'gross_loss' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'recoveries' => ['sometimes', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'source_reference' => ['nullable', 'string', 'max:255'],
        ];
    }

    private function cents(string|int|float $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad(substr($fraction, 0, 2), 2, '0');
    }

    private function amount(int $cents): string
    {
        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}
