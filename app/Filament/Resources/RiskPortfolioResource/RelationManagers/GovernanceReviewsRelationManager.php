<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GovernanceReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'governanceReviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['reviewer', 'issue']))->defaultSort('reviewed_at', 'desc')->columns([
            TextColumn::make('decision')->badge()->color(fn ($state) => $state->value === 'accepted' ? 'success' : 'danger'),
            TextColumn::make('reviewer.name')->label('Reviewer'), TextColumn::make('residual_score_snapshot')->label('Residual')->badge(),
            TextColumn::make('appetite_threshold_snapshot')->label('Appetite'), TextColumn::make('evidence_reference')->placeholder('None'),
            TextColumn::make('issue.status')->label('Issue')->badge()->placeholder('None'), TextColumn::make('next_review_at')->date(),
            TextColumn::make('reviewed_at')->dateTime(),
        ]);
    }
}
