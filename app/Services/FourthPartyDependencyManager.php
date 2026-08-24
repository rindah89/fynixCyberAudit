<?php

namespace App\Services;

use App\Enums\FourthPartyCriticality;
use App\Enums\FourthPartyDependencyCategory;
use App\Enums\FourthPartyDependencyStatus;
use App\Models\BusinessService;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorFourthPartyDependency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FourthPartyDependencyManager
{
    public function record(Vendor $vendor, User $actor, array $data): VendorFourthPartyDependency
    {
        return DB::transaction(function () use ($vendor, $actor, $data): VendorFourthPartyDependency {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            if (! $actor->can('Manage Third Party Risk')) {
                abort(403, 'You cannot record fourth-party dependencies.');
            }
            $validated = Validator::make($data, self::rules())->validate();
            if (isset($validated['fourth_party_vendor_id']) && filled($validated['fourth_party_name'] ?? null)) {
                throw ValidationException::withMessages(['fourth_party_name' => 'Choose a known fourth-party vendor or provide an external name, not both.']);
            }
            $fourthParty = isset($validated['fourth_party_vendor_id'])
                ? Vendor::query()->lockForUpdate()->findOrFail($validated['fourth_party_vendor_id'])
                : null;
            if ($fourthParty?->id === $locked->id) {
                throw ValidationException::withMessages(['fourth_party_vendor_id' => 'A vendor cannot be its own fourth party.']);
            }
            $service = isset($validated['business_service_id'])
                ? BusinessService::query()->lockForUpdate()->findOrFail($validated['business_service_id'])
                : null;
            $name = $fourthParty?->name ?? $validated['fourth_party_name'];
            $key = $fourthParty ? 'vendor:'.$fourthParty->id : 'external:'.hash('sha256', str($name)->lower()->squish()->toString());
            $version = ((int) $locked->fourthPartyDependencies()->where('dependency_key', $key)->max('version')) + 1;
            $recordedAt = now();
            $snapshot = [
                'primary_vendor' => $locked->only(['id', 'name', 'vendor_manager_id', 'status', 'risk_rating']),
                'fourth_party' => $fourthParty?->only(['id', 'name', 'status', 'risk_rating']) ?? ['id' => null, 'name' => $name],
                'business_service' => $service?->only(['id', 'owner_id', 'code', 'name', 'criticality', 'status']),
                'dependency' => [
                    'status' => $validated['status'], 'category' => $validated['category'],
                    'criticality' => $validated['criticality'], 'service_description' => $validated['service_description'],
                    'data_access' => (bool) ($validated['data_access'] ?? false),
                ],
            ];

            return $locked->fourthPartyDependencies()->create([
                'fourth_party_vendor_id' => $fourthParty?->id,
                'business_service_id' => $service?->id,
                'recorded_by' => $actor->id,
                'dependency_key' => $key,
                'version' => $version,
                'status' => $validated['status'],
                'category' => $validated['category'],
                'criticality' => $validated['criticality'],
                'fourth_party_name' => $name,
                'service_description' => $validated['service_description'],
                'data_access' => $validated['data_access'] ?? false,
                'source_reference' => $validated['source_reference'] ?? null,
                'rationale' => $validated['rationale'],
                'governance_snapshot' => $snapshot,
                'recorded_at' => $recordedAt,
            ])->load(['recorder:id,name', 'businessService:id,code,name', 'fourthPartyVendor:id,name']);
        }, 3);
    }

    public function history(Vendor $vendor, User $actor): Builder
    {
        $this->authorizeVendorView($vendor, $actor);

        return $vendor->fourthPartyDependencies()->getQuery()
            ->with(['recorder:id,name', 'businessService:id,code,name', 'fourthPartyVendor:id,name'])
            ->latest('recorded_at');
    }

    public function concentrations(User $actor, int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        if (! $actor->can('Manage Third Party Risk') && ! $actor->can('Read Vendors')) {
            abort(403, 'You cannot inspect cross-vendor concentration.');
        }

        $keys = $this->currentDependencies()->select('dependency_key')->distinct()->orderBy('dependency_key')
            ->paginate($perPage, ['dependency_key'], 'page', $page);
        $grouped = $this->currentDependencies()->whereIn('dependency_key', $keys->getCollection()->pluck('dependency_key'))->get()
            ->groupBy('dependency_key')
            ->map(function (Collection $records): array {
                $first = $records->first();
                $critical = $records->filter(fn (VendorFourthPartyDependency $record): bool => in_array($record->criticality, [FourthPartyCriticality::High, FourthPartyCriticality::Critical], true))->count();
                $vendorCount = $records->pluck('vendor_id')->unique()->count();

                return [
                    'dependency_key' => $first->dependency_key,
                    'fourth_party_name' => $first->fourth_party_name,
                    'primary_vendor_count' => $vendorCount,
                    'high_or_critical_count' => $critical,
                    'data_access_count' => $records->where('data_access', true)->count(),
                    'concentration_band' => self::band($vendorCount, $critical),
                    'primary_vendors' => $records->map(fn (VendorFourthPartyDependency $record): array => [
                        'id' => $record->vendor_id, 'name' => $record->vendor->name,
                        'criticality' => $record->criticality->value, 'category' => $record->category->value,
                    ])->values()->all(),
                ];
            })->sortByDesc('primary_vendor_count')->values();

        return new LengthAwarePaginator($grouped, $keys->total(), $keys->perPage(), $keys->currentPage(), [
            'path' => request()->url(), 'query' => request()->query(),
        ]);
    }

    public function vendorConcentration(VendorFourthPartyDependency $record): array
    {
        $records = $this->currentDependencies()->where('dependency_key', $record->dependency_key)->get();
        $critical = $records->filter(fn (VendorFourthPartyDependency $dependency): bool => in_array($dependency->criticality, [FourthPartyCriticality::High, FourthPartyCriticality::Critical], true))->count();
        $vendorCount = $records->pluck('vendor_id')->unique()->count();

        return ['primary_vendor_count' => $vendorCount, 'high_or_critical_count' => $critical, 'concentration_band' => self::band($vendorCount, $critical)];
    }

    public static function rules(): array
    {
        return [
            'fourth_party_vendor_id' => ['nullable', 'integer', 'exists:vendors,id', 'required_without:fourth_party_name'],
            'fourth_party_name' => ['nullable', 'string', 'max:255', 'required_without:fourth_party_vendor_id'],
            'business_service_id' => ['nullable', 'integer', 'exists:business_services,id'],
            'status' => ['required', Rule::enum(FourthPartyDependencyStatus::class)],
            'category' => ['required', Rule::enum(FourthPartyDependencyCategory::class)],
            'criticality' => ['required', Rule::enum(FourthPartyCriticality::class)],
            'service_description' => ['required', 'string', 'max:2000'],
            'data_access' => ['sometimes', 'boolean'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'rationale' => ['required', 'string', 'max:30000'],
        ];
    }

    private function currentDependencies(): Builder
    {
        return VendorFourthPartyDependency::query()
            ->whereHas('vendor')
            ->where('status', FourthPartyDependencyStatus::Active)
            ->whereIn('id', VendorFourthPartyDependency::query()
                ->selectRaw('MAX(id)')->groupBy('vendor_id', 'dependency_key'))
            ->with('vendor:id,name');
    }

    private function authorizeVendorView(Vendor $vendor, User $actor): void
    {
        if (! $actor->can('Manage Third Party Risk') && ! $actor->can('Read Vendors') && $vendor->vendor_manager_id !== $actor->id) {
            abort(403, 'You cannot inspect this vendor dependency history.');
        }
    }

    private static function band(int $vendorCount, int $criticalCount): string
    {
        return match (true) {
            $vendorCount >= 5 || $criticalCount >= 3 => 'high',
            $vendorCount >= 2 || $criticalCount >= 1 => 'moderate',
            default => 'limited',
        };
    }
}
