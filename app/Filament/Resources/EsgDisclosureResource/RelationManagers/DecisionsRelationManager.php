<?php

namespace App\Filament\Resources\EsgDisclosureResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DecisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'decisions';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version'), TextColumn::make('decision')->badge(), TextColumn::make('decider.name')->label('Decided by'),
            TextColumn::make('decided_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->recordActions([
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn ($record) => view('filament.esg-disclosure-decision', ['decision' => $record])),
        ]);
    }
}
