<?php

namespace App\Filament\Exports;

use App\Models\AuditCloseoutSubmission;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditCloseoutSubmissionExporter extends Exporter
{
    protected static ?string $model = AuditCloseoutSubmission::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('version'), ExportColumn::make('opinion'), ExportColumn::make('executive_summary'),
            ExportColumn::make('scope_limitations'), ExportColumn::make('significant_matters'), ExportColumn::make('recommendations_summary'),
            ExportColumn::make('audit_snapshot')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->audit_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('engagement_baseline_snapshot')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->engagement_baseline_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('audit_item_snapshots')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->audit_item_snapshots, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('data_request_snapshots')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->data_request_snapshots, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('audit_procedure_snapshots')->state(function (AuditCloseoutSubmission $record): string {
                $snapshots = collect($record->audit_procedure_snapshots)->map(function (array $procedure): array {
                    data_forget($procedure, 'execution.evidence_manifest');
                    data_forget($procedure, 'supervisory_review.execution_snapshot.evidence_manifest');

                    return $procedure;
                })->all();

                return json_encode($snapshots, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }),
            ExportColumn::make('audit_effort_snapshots')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->audit_effort_snapshots, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('audit_finding_snapshots')->state(fn (AuditCloseoutSubmission $record): string => json_encode($record->audit_finding_snapshots, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            ExportColumn::make('submitter.name'), ExportColumn::make('submitted_at'), ExportColumn::make('fingerprint'),
            ExportColumn::make('review.decision'), ExportColumn::make('review.review_summary'), ExportColumn::make('review.reviewer.name'),
            ExportColumn::make('review.reviewed_at'), ExportColumn::make('review.report_size'), ExportColumn::make('review.report_sha256'), ExportColumn::make('review.fingerprint')->label('Review Fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['submitter' => fn ($relation) => $relation->withTrashed(), 'review.reviewer' => fn ($relation) => $relation->withTrashed()]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your audit closeout export has completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
