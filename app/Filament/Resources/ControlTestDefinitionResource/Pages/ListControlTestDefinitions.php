<?php

namespace App\Filament\Resources\ControlTestDefinitionResource\Pages;

use App\Filament\Resources\ControlTestDefinitionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListControlTestDefinitions extends ListRecords
{
    protected static string $resource = ControlTestDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
