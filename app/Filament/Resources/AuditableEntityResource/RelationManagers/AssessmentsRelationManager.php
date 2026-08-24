<?php

namespace App\Filament\Resources\AuditableEntityResource\RelationManagers;

use App\Filament\Exports\AuditableEntityAssessmentExporter;
use App\Models\AuditableEntityAssessment;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    protected static ?string $title = 'Append-only risk assessment history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('assessor:id,name'))->defaultSort('version', 'desc')->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('inherent_score')->label('Inherent'),
            TextColumn::make('residual_score')->label('Residual'), TextColumn::make('priority_band')->label('Priority')->badge()->color(fn (string $state): string => match ($state) {
                'critical', 'high' => 'danger', 'medium' => 'warning', default => 'success'
            }),
            TextColumn::make('next_assessment_at')->date(), TextColumn::make('assessor.name')->label('Assessed by'), TextColumn::make('assessed_at')->dateTime(),
        ])->headerActions([ExportAction::make()->exporter(AuditableEntityAssessmentExporter::class)])
            ->recordActions([Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (AuditableEntityAssessment $record) => view('filament.auditable-entity-assessment', ['assessment' => $record]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
