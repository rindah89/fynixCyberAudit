<?php

namespace App\Filament\Resources\AiSystemResource\Pages;

use App\Filament\Resources\AiSystemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiSystems extends ListRecords
{
    protected static string $resource = AiSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
