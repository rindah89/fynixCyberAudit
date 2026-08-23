<?php

namespace App\Filament\Resources\BusinessServiceResource\Pages;

use App\Filament\Resources\BusinessServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBusinessServices extends ListRecords
{
    protected static string $resource = BusinessServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
