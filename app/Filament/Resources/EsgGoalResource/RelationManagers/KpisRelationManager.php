<?php

namespace App\Filament\Resources\EsgGoalResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KpisRelationManager extends RelationManager
{
    protected static string $relationship = 'kpis';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code'), TextColumn::make('name'), TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('last_status')->badge(), TextColumn::make('monitoring_status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())->color(fn (string $state): string => match ($state) {
                'target_met' => 'success', 'target_not_met', 'overdue' => 'warning', 'inactive' => 'gray', default => 'info',
            }),
            TextColumn::make('next_due_at')->dateTime(),
        ])->recordActions([
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(
                fn ($record) => view('filament.esg-performance-evidence', ['title' => 'KPI '.$record->code, 'snapshot' => $record->goal_snapshot, 'record' => $record]),
            ),
        ])->defaultSort('id', 'desc');
    }
}
