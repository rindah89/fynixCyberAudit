<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class DependenciesRelationManager extends RelationManager
{
    protected static string $relationship = 'dependencies';

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->with(['dependentService', 'application', 'asset', 'vendor', 'control']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('target_type')->label('Type')->badge(), TextColumn::make('target_label')->label('Dependency'),
            TextColumn::make('dependency_type'), TextColumn::make('criticality')->badge(), TextColumn::make('notes')->wrap(),
        ]);
    }
}
