<?php

namespace App\Filament\Resources\PolicyResource\RelationManagers;

use App\Enums\PolicyRevisionDecision;
use App\Enums\PolicyRevisionStatus;
use App\Filament\Exports\PolicyRevisionExporter;
use App\Models\PolicyRevision;
use App\PolicyCompliance\PolicyRevisionManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RevisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'revisions';

    protected static ?string $title = 'Governed revision history';

    public function table(Table $table): Table
    {
        return $table
            ->description(fn (): string => 'Current policy governance state: '.str($this->getOwnerRecord()->revision_governance_status)->replace('_', ' ')->title())
            ->modifyQueryUsing(fn ($query) => $query->with(['submitter:id,name', 'review.reviewer:id,name']))
            ->defaultSort('version', 'desc')
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state->getColor()),
                TextColumn::make('change_summary')->limit(80),
                TextColumn::make('proposed_effective_date')->date()->sortable(),
                TextColumn::make('submitter.name')->label('Submitted by'),
                TextColumn::make('review.reviewer.name')->label('Reviewed by'),
                TextColumn::make('submitted_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('submit_revision')->label('Submit revision')->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => auth()->user()?->can('Update Policies') || $this->getOwnerRecord()->owner_id === auth()->id())
                    ->schema([
                        Textarea::make('change_summary')->required()->maxLength(30000),
                        DatePicker::make('proposed_effective_date')->required(),
                    ])->action(fn (array $data) => app(PolicyRevisionManager::class)->submit($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(PolicyRevisionExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('Read Policies')),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading(fn (PolicyRevision $record): string => "Policy revision v{$record->version}")
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (PolicyRevision $record) => view('filament.policy-revision', ['revision' => $record])),
                Action::make('review')->label('Review')->icon('heroicon-o-check-badge')
                    ->visible(fn (PolicyRevision $record): bool => $record->status === PolicyRevisionStatus::PendingReview
                        && auth()->user()?->can('Update Policies') && $record->submitted_by !== auth()->id())
                    ->schema([
                        Select::make('decision')->options(PolicyRevisionDecision::class)->required(),
                        Textarea::make('review_summary')->required()->maxLength(30000),
                    ])->action(fn (PolicyRevision $record, array $data) => app(PolicyRevisionManager::class)->review($record, auth()->user(), $data)),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
