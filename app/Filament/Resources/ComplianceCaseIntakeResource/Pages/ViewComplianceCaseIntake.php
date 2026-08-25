<?php

namespace App\Filament\Resources\ComplianceCaseIntakeResource\Pages;

use App\ComplianceCases\ComplianceCaseIntakeManager;
use App\Enums\ComplianceCaseIntakeDecision;
use App\Filament\Resources\ComplianceCaseIntakeResource;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

class ViewComplianceCaseIntake extends ViewRecord
{
    protected static string $resource = ComplianceCaseIntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('decide')->label('Record disposition')->icon('heroicon-o-check-circle')
                ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true && $this->getRecord()->decision === null)
                ->schema([
                    Select::make('decision')->options(collect(ComplianceCaseIntakeDecision::cases())->mapWithKeys(fn (ComplianceCaseIntakeDecision $decision): array => [$decision->value => $decision->getLabel()])->all())->required(),
                    Textarea::make('summary')->label('Disposition rationale')->required()->maxLength(30000)->columnSpanFull(),
                ])->action(function (array $data): void {
                    app(ComplianceCaseIntakeManager::class)->decide(auth()->user(), $this->getRecord(), $data);
                    $this->refreshFormData([]);
                }),
        ];
    }
}
