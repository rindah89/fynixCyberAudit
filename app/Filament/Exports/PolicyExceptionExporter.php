<?php

namespace App\Filament\Exports;

use App\Models\PolicyException;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class PolicyExceptionExporter extends Exporter
{
    protected static ?string $model = PolicyException::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('policy.code')->label('Policy Code'), ExportColumn::make('name'),
            ExportColumn::make('status')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('description'), ExportColumn::make('justification'), ExportColumn::make('risk_assessment'),
            ExportColumn::make('compensating_controls'), ExportColumn::make('effective_date'), ExportColumn::make('expiration_date'),
            ExportColumn::make('review_frequency_days'), ExportColumn::make('next_review_at'),
            ExportColumn::make('latest_monitoring_outcome')->formatStateUsing(fn ($state): ?string => $state?->value),
            ExportColumn::make('monitoring_status')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('requester.name')->label('Requested By'), ExportColumn::make('submitted_at'),
            ExportColumn::make('governance_fingerprint'),
            ExportColumn::make('governance_snapshot')->formatStateUsing(fn ($state): ?string => $state ? json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null),
            ExportColumn::make('decision_history')->state(fn (PolicyException $record): string => json_encode($record->decisions->map(fn ($decision): array => [
                'version' => $decision->version, 'decision' => $decision->decision->value,
                'summary' => $decision->decision_summary, 'decided_by' => $decision->decided_by,
                'decided_at' => $decision->decided_at?->toISOString(), 'fingerprint' => $decision->fingerprint,
                'exception_snapshot' => $decision->exception_snapshot,
            ])->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ExportColumn::make('monitoring_history')->state(fn (PolicyException $record): string => json_encode($record->monitoringReviews->map(fn ($review): array => [
                'version' => $review->version, 'outcome' => $review->outcome->value,
                'review_summary' => $review->review_summary, 'control_effectiveness' => $review->control_effectiveness,
                'evidence_reference' => $review->evidence_reference, 'reviewed_by' => $review->reviewed_by,
                'reviewed_at' => $review->reviewed_at?->toISOString(), 'next_review_at' => $review->next_review_at?->toISOString(),
                'fingerprint' => $review->fingerprint, 'exception_snapshot' => $review->exception_snapshot,
                'governance_issue' => $review->issue ? [
                    'id' => $review->issue->id, 'status' => $review->issue->status->value,
                    'severity' => $review->issue->severity, 'remediation_task_id' => $review->issue->remediation_task_id,
                ] : null,
            ])->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with([
            'policy:id,code', 'requester:id,name', 'decisions.decider:id,name',
            'monitoringReviews.reviewer:id,name', 'monitoringReviews.issue.lifecycle',
            'openMonitoringIssues' => fn ($issues) => $issues->select([
                'policy_exception_monitoring_issues.id',
                'policy_exception_monitoring_issues.policy_exception_monitoring_review_id',
                'policy_exception_monitoring_issues.status',
            ]),
        ]);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your policy exception export completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
