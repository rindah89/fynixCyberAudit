<?php

namespace App\Filament\Resources\PolicyObligationResource\Pages;

use App\Filament\Resources\PolicyObligationResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPolicyObligation extends EditRecord
{
    protected static string $resource = PolicyObligationResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }
}
