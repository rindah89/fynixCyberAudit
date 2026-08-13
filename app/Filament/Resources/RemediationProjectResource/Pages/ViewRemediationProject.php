<?php

namespace App\Filament\Resources\RemediationProjectResource\Pages;

use App\Filament\Resources\RemediationProjectResource;
use App\Suite\PpmGateway;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRemediationProject extends ViewRecord
{
    protected static string $resource = RemediationProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish_to_ppm')
                ->label('Open in PPM')
                ->visible(fn (): bool => app(PpmGateway::class)->enabled())
                ->requiresConfirmation()
                ->modalHeading('Open this POA&M in Fynix PPM')
                ->modalDescription('Creates or reuses a PPM project. Gantt, members, and timesheets stay in PPM. This GRC row remains the plan of record.')
                ->action(function (): void {
                    $link = app(PpmGateway::class)->publishProject(auth()->user(), $this->record);
                    Notification::make()
                        ->title('PPM project linked')
                        ->body($link->remote_url ?: $link->entity_id)
                        ->success()
                        ->send();
                }),
        ];
    }
}
