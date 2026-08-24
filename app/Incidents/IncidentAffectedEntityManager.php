<?php

namespace App\Incidents;

use App\Enums\IncidentAffectedEntityType;
use App\Models\Application;
use App\Models\Asset;
use App\Models\Control;
use App\Models\Incident;
use App\Models\IncidentAffectedEntity;
use App\Models\Risk;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Enterprise;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class IncidentAffectedEntityManager
{
    /** @param array{entity_type:string,entity_id:int,impact_summary:string,control_failure_note?:string|null} $data */
    public function link(User $actor, Incident $incident, array $data): IncidentAffectedEntity
    {
        Enterprise::assertEnabled('incidents');
        $data = Validator::make($data, [
            'entity_type' => ['required', Rule::enum(IncidentAffectedEntityType::class)],
            'entity_id' => 'required|integer|min:1',
            'impact_summary' => 'required|string|max:30000',
            'control_failure_note' => 'nullable|string|max:30000',
        ])->validate();

        return DB::transaction(function () use ($actor, $incident, $data): IncidentAffectedEntity {
            $lockedIncident = Incident::query()->lockForUpdate()->findOrFail($incident->id);
            abort_unless($actor->can('update', $lockedIncident), 403, 'You cannot govern affected entities for this incident.');
            abort_if($lockedIncident->governed_at === null, 422, 'Legacy incidents cannot enter governed affected-entity history.');
            if ($lockedIncident->affectedEntities()->count() >= 100) {
                throw ValidationException::withMessages(['incident' => 'A governed incident is limited to 100 affected entities.']);
            }
            $type = IncidentAffectedEntityType::from($data['entity_type']);
            if ($lockedIncident->affectedEntities()->where('entity_type', $type->value)->where('entity_id_snapshot', $data['entity_id'])->exists()) {
                throw ValidationException::withMessages(['entity_id' => 'This entity is already retained in the governed incident scope.']);
            }
            if ($type === IncidentAffectedEntityType::Control && blank($data['control_failure_note'] ?? null)) {
                throw ValidationException::withMessages(['control_failure_note' => 'An affected control requires a failure note.']);
            }
            $entity = $this->lockEntity($type, (int) $data['entity_id']);
            abort_unless($actor->can('view', $entity), 403, 'You cannot view the selected affected entity.');
            $linkedAt = now();
            $payload = [
                'incident_id' => $lockedIncident->id,
                'entity_type' => $type->value,
                'entity_id_snapshot' => $entity->getKey(),
                'entity_snapshot' => $this->snapshot($type, $entity),
                'impact_summary' => $data['impact_summary'],
                'control_failure_note' => $data['control_failure_note'] ?? null,
                'linked_by' => $actor->id,
                'linked_at' => $linkedAt->toIso8601String(),
            ];

            return $lockedIncident->affectedEntities()->create($payload + [
                'fingerprint' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            ]);
        }, 3);
    }

    private function lockEntity(IncidentAffectedEntityType $type, int $id): Model
    {
        $class = match ($type) {
            IncidentAffectedEntityType::Asset => Asset::class,
            IncidentAffectedEntityType::Application => Application::class,
            IncidentAffectedEntityType::Vendor => Vendor::class,
            IncidentAffectedEntityType::Control => Control::class,
            IncidentAffectedEntityType::Risk => Risk::class,
        };

        return $class::query()->lockForUpdate()->findOrFail($id);
    }

    /** @return array<string,mixed> */
    private function snapshot(IncidentAffectedEntityType $type, Model $entity): array
    {
        $fields = match ($type) {
            IncidentAffectedEntityType::Asset => ['id', 'asset_tag', 'name', 'serial_number', 'manufacturer', 'model', 'hostname', 'ip_address', 'status_id', 'assigned_to_user_id', 'department_id', 'location_id'],
            IncidentAffectedEntityType::Application => ['id', 'name', 'owner_id', 'type', 'description', 'status', 'url', 'vendor_id'],
            IncidentAffectedEntityType::Vendor => ['id', 'name', 'vendor_manager_id', 'status', 'risk_rating', 'risk_score'],
            IncidentAffectedEntityType::Control => ['id', 'standard_id', 'control_owner_id', 'code', 'title', 'status', 'effectiveness', 'type', 'category'],
            IncidentAffectedEntityType::Risk => ['id', 'code', 'name', 'domain', 'status', 'residual_likelihood', 'residual_impact', 'residual_risk', 'is_active'],
        };

        return collect($fields)->mapWithKeys(function (string $field) use ($entity): array {
            $value = $entity->getAttribute($field);

            return [$field => $value instanceof BackedEnum ? $value->value : $value];
        })->all();
    }
}
