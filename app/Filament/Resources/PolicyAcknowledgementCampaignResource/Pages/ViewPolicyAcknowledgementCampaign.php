<?php

namespace App\Filament\Resources\PolicyAcknowledgementCampaignResource\Pages;

use App\Filament\Resources\PolicyAcknowledgementCampaignResource;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewPolicyAcknowledgementCampaign extends ViewRecord
{
    protected static string $resource = PolicyAcknowledgementCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('close')->color('danger')->requiresConfirmation()
            ->visible(fn (): bool => ! $this->getRecord()->closed_at)
            ->action(fn () => app(PolicyAcknowledgementManager::class)->close($this->getRecord(), auth()->user()))];
    }
}
