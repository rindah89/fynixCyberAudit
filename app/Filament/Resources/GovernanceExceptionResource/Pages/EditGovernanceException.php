<?php

namespace App\Filament\Resources\GovernanceExceptionResource\Pages;

use App\Filament\Resources\GovernanceExceptionResource;
use Carbon\CarbonImmutable;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditGovernanceException extends EditRecord
{
    protected static string $resource = GovernanceExceptionResource::class;

    /** @param array<string, mixed> $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['status'] === 'waived' && CarbonImmutable::parse($data['due_at'])->isPast()) {
            throw ValidationException::withMessages(['due_at' => 'A waiver expiry must be in the future.']);
        }

        $data['resolved_at'] = $data['status'] === 'resolved' ? now() : null;

        return $data;
    }
}
