<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecoveryPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'recoveryPlans';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version'), TextColumn::make('title')->wrap(), TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('status')->badge(), TextColumn::make('review_due_at')->date(), TextColumn::make('exercises_count')->counts('exercises')->label('Exercises'),
        ])->defaultSort('version', 'desc');
    }
}
