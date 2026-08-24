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
            ExportColumn::make('requester.name')->label('Requested By'), ExportColumn::make('submitted_at'),
            ExportColumn::make('governance_fingerprint'),
            ExportColumn::make('governance_snapshot')->formatStateUsing(fn ($state): ?string => $state ? json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null),
            ExportColumn::make('decision_history')->state(fn (PolicyException $record): string => json_encode($record->decisions->map(fn ($decision): array => [
                'version' => $decision->version, 'decision' => $decision->decision->value,
                'summary' => $decision->decision_summary, 'decided_by' => $decision->decided_by,
                'decided_at' => $decision->decided_at?->toISOString(), 'fingerprint' => $decision->fingerprint,
                'exception_snapshot' => $decision->exception_snapshot,
            ])->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['policy:id,code', 'requester:id,name', 'decisions.decider:id,name']);
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
