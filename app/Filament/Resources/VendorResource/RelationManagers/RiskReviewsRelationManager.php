<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RiskReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskReviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['reviewer', 'decision', 'issue']))->defaultSort('reviewed_at', 'desc')->columns([
            TextColumn::make('outcome')->badge()->color(fn ($state) => match ($state->value) {
                'satisfactory' => 'success', 'needs_action' => 'warning', 'terminate' => 'danger', default => 'gray',
            }), TextColumn::make('assessment_version')->label('Assessment'), TextColumn::make('reviewer.name')->label('Reviewer'),
            TextColumn::make('evidence_reference')->placeholder('None'), TextColumn::make('issue.status')->label('Issue')->badge()->placeholder('None'),
            TextColumn::make('next_review_at')->date(), TextColumn::make('reviewed_at')->dateTime(),
        ]);
    }
}
