<?php

namespace App\Filament\Resources\ControlTestDefinitionResource\Pages;

use App\ContinuousControlTesting\ControlTestRunner;
use App\Filament\Resources\ControlTestDefinitionResource;
use App\Models\Control;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateControlTestDefinition extends CreateRecord
{
    protected static string $resource = ControlTestDefinitionResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        app(ControlTestRunner::class)->validateThreshold($data['metric_type'], $data['operator'], $data['expected_value']);
        if (! empty($data['implementation_id']) && ! Control::findOrFail($data['control_id'])->implementations()->whereKey($data['implementation_id'])->exists()) {
            throw ValidationException::withMessages(['implementation_id' => 'The implementation must be mapped to this control.']);
        }

        return $data;
    }
}
