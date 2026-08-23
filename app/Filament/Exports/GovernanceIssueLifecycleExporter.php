<?php

namespace App\Filament\Exports;

use App\Models\GovernanceIssueLifecycle;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class GovernanceIssueLifecycleExporter extends Exporter
{
    protected static ?string $model = GovernanceIssueLifecycle::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id'),
            ExportColumn::make('source_type')->formatStateUsing(fn ($state) => $state->getLabel()),
            ExportColumn::make('issue.title')->label('Issue'),
            ExportColumn::make('issue.owner.name')->label('Issue Owner'),
            ExportColumn::make('status')->formatStateUsing(fn ($state) => $state->getLabel()),
            ExportColumn::make('remediationTask.number')->label('Remediation Task'),
            ExportColumn::make('remediationTask.status')->label('Task Status'),
            ExportColumn::make('due_at'),
            ExportColumn::make('verifier.name')->label('Verified By'),
            ExportColumn::make('verified_at'),
            ExportColumn::make('closed_at'),
            ExportColumn::make('verification_summary'),
            ExportColumn::make('evidence_reference')->label('Operator Evidence Reference'),
            ExportColumn::make('closure_evidence_count')->label('Governed Evidence Files')
                ->state(fn (GovernanceIssueLifecycle $record): int => $record->closureEvidence->count()),
            ExportColumn::make('closure_evidence_sha256')->label('Closure Evidence SHA-256')
                ->state(fn (GovernanceIssueLifecycle $record): string => $record->closureEvidence->pluck('sha256')->implode(', ')),
            ExportColumn::make('closure_evidence_audit_ids')->label('Closure Evidence Audit IDs')
                ->state(fn (GovernanceIssueLifecycle $record): string => $record->closureEvidence->pluck('audit_id_snapshot')->implode(', ')),
            ExportColumn::make('closure_evidence_response_ids')->label('Closure Evidence Response IDs')
                ->state(fn (GovernanceIssueLifecycle $record): string => $record->closureEvidence->pluck('data_request_response_id_snapshot')->implode(', ')),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->withIssueGraph();
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your governance issue lifecycle export completed with '.number_format($export->successful_rows).' successful rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
