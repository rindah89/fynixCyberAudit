<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Filament\Exports\AuditCloseoutSubmissionExporter;
use App\Models\AuditCloseoutSubmission;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CloseoutSubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'closeoutSubmissions';

    protected static ?string $title = 'Append-only closeout and independent-review history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['submitter:id,name', 'review.reviewer:id,name']))
            ->defaultSort('version', 'desc')->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('opinion')->badge(),
                TextColumn::make('submitter.name')->label('Submitted by'), TextColumn::make('submitted_at')->dateTime(),
                TextColumn::make('review.decision')->label('Review')->badge()->placeholder('Pending'),
                TextColumn::make('review.reviewer.name')->label('Reviewed by')->placeholder('Pending'),
                TextColumn::make('review.reviewed_at')->dateTime()->placeholder('Pending'),
            ])->headerActions([ExportAction::make()->exporter(AuditCloseoutSubmissionExporter::class)])
            ->recordActions([Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (AuditCloseoutSubmission $record) => view('filament.audit-closeout-submission', ['submission' => $record]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
