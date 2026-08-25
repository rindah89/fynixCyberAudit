<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseInvestigationReportManager;
use App\Enums\ComplianceCaseInvestigationReportDecision;
use App\Enums\ComplianceCaseInvestigationReportOutcome;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseInvestigationReport;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvestigationReportsRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationReports';

    private ?bool $currentActorHasProcedureConflict = null;

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['author:id,name,email', 'review.reviewer:id,name,email']))->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('outcome')->badge(),
            TextColumn::make('author.name')->searchable(), TextColumn::make('authored_at')->dateTime()->sortable(),
            TextColumn::make('review.decision')->label(__('Review'))->badge()->placeholder(__('Pending')),
            TextColumn::make('fingerprint')->copyable()->toggleable(),
        ])->headerActions([
            Action::make('submit_report')->label(__('Submit investigation report'))->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->getOwnerRecord()->investigation_reporting_governed_at !== null
                    && in_array($this->getOwnerRecord()->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)
                    && (auth()->user()->can('Manage Compliance Cases')
                        || (auth()->user()->can('Investigate Compliance Cases') && $this->getOwnerRecord()->assigned_to === auth()->id())))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('outcome')->options(ComplianceCaseInvestigationReportOutcome::class)->required(),
                    Textarea::make('executive_summary')->required()->maxLength(30000)->columnSpanFull(),
                    Textarea::make('analysis')->required()->maxLength(30000)->columnSpanFull(),
                    Textarea::make('findings')->required()->maxLength(30000)->columnSpanFull(),
                    Textarea::make('recommendations')->required()->maxLength(30000)->columnSpanFull(),
                ]))->action(fn (array $data) => app(ComplianceCaseInvestigationReportManager::class)->submit(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([
            Action::make('review')->label(__('Review report'))->icon('heroicon-o-check-badge')
                ->visible(fn (ComplianceCaseInvestigationReport $record): bool => $this->canReview($record))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('decision')->options(ComplianceCaseInvestigationReportDecision::class)->required(),
                    Textarea::make('summary')->required()->maxLength(30000),
                ]))->action(fn (ComplianceCaseInvestigationReport $record, array $data) => app(ComplianceCaseInvestigationReportManager::class)->review(auth()->user(), $record, $data)),
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn (ComplianceCaseInvestigationReport $record) => view('filament.compliance-case-investigation-report', [
                    'report' => $record->fresh()->load(['author', 'review.reviewer']),
                ])),
        ])->defaultSort('version', 'desc');
    }

    private function canReview(ComplianceCaseInvestigationReport $report): bool
    {
        if (! auth()->user()->can('Manage Compliance Cases') || $report->review !== null
            || $report->authored_by === auth()->id() || $this->getOwnerRecord()->assigned_to === auth()->id()
            || ! in_array($this->getOwnerRecord()->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)) {
            return false;
        }

        return ! ($this->currentActorHasProcedureConflict ??= $this->getOwnerRecord()->investigationProcedureExecutions()
            ->where(fn ($query) => $query->where('executed_by', auth()->id())
                ->orWhereHas('review', fn ($review) => $review->where('reviewed_by', auth()->id())))
            ->exists());
    }
}
