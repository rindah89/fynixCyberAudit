<?php

namespace App\Filament\Resources\BusinessServiceResource\Pages;

use App\Filament\Resources\BusinessServiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewBusinessService extends ViewRecord
{
    protected static string $resource = BusinessServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
