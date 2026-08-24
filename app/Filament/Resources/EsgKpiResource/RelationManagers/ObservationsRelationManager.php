<?php

namespace App\Filament\Resources\EsgKpiResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ObservationsRelationManager extends RelationManager
{
    protected static string $relationship = 'observations';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version'), TextColumn::make('observed_value'), TextColumn::make('status')->badge(),
            TextColumn::make('observer.name')->label('Observed by'), TextColumn::make('observed_at')->dateTime(),
            TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->recordActions([
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(
                fn ($record) => view('filament.esg-performance-evidence', ['title' => 'KPI observation '.$record->version, 'snapshot' => $record->kpi_snapshot, 'record' => $record]),
            ),
        ])->defaultSort('version', 'desc');
    }
}
