<?php

namespace App\Filament\Resources\PolicyAcknowledgementResource\Pages;

use App\Filament\Resources\PolicyAcknowledgementResource;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewPolicyAcknowledgement extends ViewRecord
{
    protected static string $resource = PolicyAcknowledgementResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('acknowledge')->label('Acknowledge policy')->icon('heroicon-o-check-circle')
            ->visible(fn (): bool => ! $this->getRecord()->acknowledgement && ! $this->getRecord()->campaign->closed_at)
            ->schema([
                Checkbox::make('acknowledged')->label(PolicyAcknowledgementManager::STATEMENT)->accepted()->required(),
                Textarea::make('comment')->maxLength(2000),
                TextInput::make('client_reference')->maxLength(255),
            ])->action(fn (array $data) => app(PolicyAcknowledgementManager::class)->acknowledge($this->getRecord(), auth()->user(), $data))];
    }
}
