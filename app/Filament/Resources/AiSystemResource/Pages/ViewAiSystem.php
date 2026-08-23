<?php

namespace App\Filament\Resources\AiSystemResource\Pages;

use App\Filament\Resources\AiSystemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAiSystem extends ViewRecord
{
    protected static string $resource = AiSystemResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
