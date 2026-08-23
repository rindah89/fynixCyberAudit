<?php

namespace App\Filament\Resources\PolicyObligationResource\Pages;

use App\Filament\Resources\PolicyObligationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPolicyObligations extends ListRecords
{
    protected static string $resource = PolicyObligationResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
