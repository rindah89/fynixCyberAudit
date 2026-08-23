<?php

namespace App\Filament\Resources\GovernanceIssueLifecycleResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transitions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))->defaultSort('transitioned_at', 'desc')->columns([
            TextColumn::make('from_status')->label('From')->badge()->placeholder('Created'),
            TextColumn::make('to_status')->label('To')->badge(),
            TextColumn::make('actor.name')->label('Actor'),
            TextColumn::make('rationale')->wrap()->limit(100),
            TextColumn::make('remediation_task_snapshot.number')->label('Task')->placeholder('None'),
            TextColumn::make('evidence_reference')->label('Evidence reference')->placeholder('None'),
            TextColumn::make('transitioned_at')->dateTime()->sortable(),
        ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
