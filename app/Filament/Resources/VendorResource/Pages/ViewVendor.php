<?php

namespace App\Filament\Resources\VendorResource\Pages;

use App\Filament\Resources\VendorResource;
use App\Services\VendorAssessmentService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewVendor extends ViewRecord
{
    protected static string $resource = VendorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('assess_risk')
                ->label(__('Assess Risk'))
                ->icon('heroicon-o-clipboard-document-check')
                ->color('primary')
                ->visible(fn (): bool => auth()->user()?->can('Manage Vendor Management') ?? false)
                ->schema(VendorAssessmentService::getAssessRiskFormSchema())
                ->action(fn (array $data) => VendorAssessmentService::handleAssessRisk($this->record, $data)),
            EditAction::make(),
        ];
    }
}
