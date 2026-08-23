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
