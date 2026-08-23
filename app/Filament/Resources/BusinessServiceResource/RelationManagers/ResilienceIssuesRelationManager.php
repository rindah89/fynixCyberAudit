<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ResilienceIssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'resilienceIssues';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->wrap(), TextColumn::make('severity')->badge(), TextColumn::make('status')->badge(),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('due_at')->date()->placeholder('None'),
            TextColumn::make('remediationTask.number')->label('Remediation')->placeholder('Not linked'),
        ])->defaultSort('created_at', 'desc');
    }
}
