<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhaseTransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'phaseTransitions';

    protected static ?string $title = 'Governed phase history';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))
            ->columns([
                TextColumn::make('from_phase')->label('From')->badge()->placeholder('Created'),
                TextColumn::make('to_phase')->label('To')->badge(),
                TextColumn::make('summary')->wrap()->limit(120),
                TextColumn::make('actor.name')->label('Actor'),
                TextColumn::make('transitioned_at')->dateTime()->sortable(),
                TextColumn::make('fingerprint')->copyable()->limit(12),
            ])
            ->defaultSort('id');
    }
}
