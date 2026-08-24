<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Enums\RiskDomain;
use App\Filament\Exports\RiskIndicatorObservationExporter;
use App\Models\RiskIndicatorObservation;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RiskIndicatorObservationsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskIndicatorObservations';

    protected static ?string $title = 'KRI observation history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->domain === RiskDomain::Operational;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['indicator:id,code,name', 'observer:id,name']))->defaultSort('observed_at', 'desc')->columns([
            TextColumn::make('indicator.code')->label('KRI'), TextColumn::make('observed_value'), TextColumn::make('unit_snapshot')->label('Unit'),
            TextColumn::make('status')->badge()->color(fn ($state) => match ($state->value ?? $state) {
                'normal' => 'success', 'warning' => 'warning', 'critical' => 'danger', default => 'gray'
            }),
            TextColumn::make('reason')->wrap()->limit(100), TextColumn::make('observer.name')->label('Observed by'), TextColumn::make('observed_at')->dateTime()->sortable(),
        ])->headerActions([ExportAction::make()->exporter(RiskIndicatorObservationExporter::class)->visible(fn () => auth()->user()?->can('Manage Risk Portfolio') || auth()->user()?->can('Read Risks'))])
            ->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading('KRI observation snapshot')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (RiskIndicatorObservation $record) => view('filament.risk-indicator-observation', ['observation' => $record])),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
