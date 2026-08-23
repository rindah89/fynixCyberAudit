<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RiskDecisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskDecisions';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('decider'))->defaultSort('decided_at', 'desc')->columns([
            TextColumn::make('decision')->badge()->color(fn ($state) => match ($state->value) {
                'approved' => 'success', 'conditionally_approved' => 'warning', 'rejected', 'terminated' => 'danger', default => 'gray',
            }), TextColumn::make('assessment_version')->label('Assessment'), TextColumn::make('residual_score')->badge(),
            TextColumn::make('decider.name')->label('Decision maker'), TextColumn::make('next_review_at')->date(),
            TextColumn::make('expires_at')->date(), TextColumn::make('decided_at')->dateTime(),
        ]);
    }
}
