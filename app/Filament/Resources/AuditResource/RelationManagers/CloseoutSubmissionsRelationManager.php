<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Access\FileAccess;
use App\Filament\Exports\AuditCloseoutSubmissionExporter;
use App\Models\AuditCloseoutSubmission;
use App\Models\FileAttachment;
use App\Models\User;
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
                ->modalContent(fn (AuditCloseoutSubmission $record) => view('filament.audit-closeout-submission', ['submission' => $this->visibleSubmission($record)]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    private function visibleSubmission(AuditCloseoutSubmission $record): AuditCloseoutSubmission
    {
        $visible = clone $record;
        $actor = auth()->user();
        if (! $actor instanceof User) {
            $visible->setAttribute('audit_procedure_snapshots', []);

            return $visible;
        }
        $snapshots = $record->audit_procedure_snapshots ?? [];
        $attachmentIds = collect($snapshots)->flatMap(fn (array $procedure) => collect(data_get($procedure, 'execution.evidence_manifest', []))->pluck('file_attachment_id'))->unique()->values();
        $attachments = FileAttachment::query()->whereKey($attachmentIds)->with([
            'audit.members', 'dataRequestResponse.dataRequest.audit.members',
        ])->get()->keyBy('id');
        $authorizedIds = $attachments->filter(fn (FileAttachment $attachment): bool => app(FileAccess::class)->canDownloadFileAttachment($actor, $attachment))->keys();
        $redact = fn (array $items): array => collect($items)->map(function (array $procedure) use ($authorizedIds): array {
            data_set($procedure, 'execution.evidence_manifest', collect(data_get($procedure, 'execution.evidence_manifest', []))->whereIn('file_attachment_id', $authorizedIds)->values()->all());
            data_set($procedure, 'supervisory_review.execution_snapshot.evidence_manifest', collect(data_get($procedure, 'supervisory_review.execution_snapshot.evidence_manifest', []))->whereIn('file_attachment_id', $authorizedIds)->values()->all());

            return $procedure;
        })->all();
        $visible->setAttribute('audit_procedure_snapshots', $redact($snapshots));
        if ($record->review) {
            $visibleReview = clone $record->review;
            $report = $visibleReview->report_snapshot;
            $report['audit_procedure_snapshots'] = $redact($report['audit_procedure_snapshots'] ?? []);
            $visibleReview->setAttribute('report_snapshot', $report);
            $visible->setRelation('review', $visibleReview);
        }

        return $visible;
    }
}
