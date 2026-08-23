<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RiskAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskAssessments';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['assessor', 'survey']))->defaultSort('version', 'desc')->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('assessor.name')->label('Assessor'),
            TextColumn::make('inherent_score')->label('Inherent')->badge(), TextColumn::make('residual_score')->label('Residual')->badge(),
            TextColumn::make('survey_score_snapshot')->label('Survey score')->placeholder('Manual'),
            TextColumn::make('risk_categories')->badge(), TextColumn::make('assessed_at')->dateTime(),
        ]);
    }
}
