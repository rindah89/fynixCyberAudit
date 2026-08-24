<?php

namespace App\Filament\Resources\EsgMaterialTopicResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    public function table(Table $t): Table
    {
        return $t->columns([TextColumn::make('version'), TextColumn::make('decision')->badge(), TextColumn::make('impact_materiality')->label('Impact'), TextColumn::make('financial_materiality')->label('Financial'), TextColumn::make('assessor.name')->label('Assessor'), TextColumn::make('next_review_at')->date(), TextColumn::make('fingerprint')->limit(12)->copyable()])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn ($record) => view('filament.esg-materiality-evidence', ['title' => 'Materiality assessment '.$record->version, 'snapshot' => $record->topic_snapshot, 'summary' => $record->decision_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
