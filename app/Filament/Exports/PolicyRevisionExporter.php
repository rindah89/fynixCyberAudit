<?php

namespace App\Filament\Exports;

use App\Models\PolicyRevision;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class PolicyRevisionExporter extends Exporter
{
    protected static ?string $model = PolicyRevision::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('policy.code')->label('Policy Code'),
            ExportColumn::make('version'),
            ExportColumn::make('status')->formatStateUsing(fn ($state): string => $state->getLabel()),
            ExportColumn::make('change_summary'),
            ExportColumn::make('proposed_effective_date'),
            ExportColumn::make('submitter.name')->label('Submitted By'),
            ExportColumn::make('submitted_at'),
            ExportColumn::make('fingerprint'),
            ExportColumn::make('policy_snapshot')->label('Policy Snapshot JSON')
                ->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            ExportColumn::make('review.decision')->formatStateUsing(fn ($state): ?string => $state?->getLabel()),
            ExportColumn::make('review.review_summary'),
            ExportColumn::make('review.reviewer.name')->label('Reviewed By'),
            ExportColumn::make('review.reviewed_at'),
            ExportColumn::make('review.fingerprint')->label('Review Fingerprint'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['policy:id,code', 'submitter:id,name', 'review.reviewer:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your policy revision export completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
