<?php

namespace App\Filament\Resources\ControlTestDefinitionResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ExecutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'executions';

    protected static ?string $title = 'Append-only execution history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->recordTitleAttribute('id')->columns([
            TextColumn::make('executed_at')->dateTime()->sortable(),
            TextColumn::make('executor.name')->label('Executed by'),
            TextColumn::make('observed_value'),
            TextColumn::make('operator'),
            TextColumn::make('expected_value'),
            TextColumn::make('outcome')->badge()->color(fn ($state) => ($state?->value ?? $state) === 'passed' ? 'success' : 'danger'),
            TextColumn::make('result_reason')->wrap(),
            TextColumn::make('finding.status')->label('Finding')->badge()->placeholder('None'),
            TextColumn::make('evidence_reference')->placeholder('None'),
        ])->defaultSort('executed_at', 'desc');
    }
}
