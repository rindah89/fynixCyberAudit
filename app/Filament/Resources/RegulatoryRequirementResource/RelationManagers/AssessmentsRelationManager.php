<?php

namespace App\Filament\Resources\RegulatoryRequirementResource\RelationManagers;

use App\Filament\Exports\RegulatoryChangeAssessmentExporter;
use App\Models\RegulatoryChangeAssessment;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    protected static ?string $title = 'Append-only change assessments';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['requirementVersion:id,regulatory_requirement_id,version,title', 'assessor:id,name', 'actionOwner:id,name']))
            ->defaultSort('assessed_at', 'desc')->columns([
                TextColumn::make('requirementVersion.version')->label('Requirement version'),
                TextColumn::make('assessment_version')->label('Assessment'),
                TextColumn::make('applicability')->badge(),
                TextColumn::make('impact')->badge(),
                TextColumn::make('actionOwner.name')->label('Action owner'), TextColumn::make('action_due_at')->date(),
                TextColumn::make('assessor.name')->label('Assessed by'), TextColumn::make('assessed_at')->dateTime(),
            ])->headerActions([ExportAction::make()->exporter(RegulatoryChangeAssessmentExporter::class)])
            ->recordActions([Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (RegulatoryChangeAssessment $record) => view('filament.regulatory-change-assessment', ['assessment' => $record]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
