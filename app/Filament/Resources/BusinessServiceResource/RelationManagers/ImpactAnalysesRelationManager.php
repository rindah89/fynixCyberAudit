<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ImpactAnalysesRelationManager extends RelationManager
{
    protected static string $relationship = 'impactAnalyses';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version'), TextColumn::make('operational_impact')->badge(),
            TextColumn::make('maximum_tolerable_downtime_minutes')->label('MTD (min)'),
            TextColumn::make('recovery_time_objective_minutes')->label('RTO (min)'),
            TextColumn::make('recovery_point_objective_minutes')->label('RPO (min)'),
            TextColumn::make('approved_at')->dateTime()->placeholder('Draft'),
        ])->defaultSort('version', 'desc');
    }
}
