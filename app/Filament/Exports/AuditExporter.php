<?php

namespace App\Filament\Exports;

use App\Models\Audit;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditExporter extends Exporter
{
    protected static ?string $model = Audit::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('title')
                ->label('Title'),
            ExportColumn::make('audit_type')
                ->label('Audit Type'),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('program.name')
                ->label('Program'),
            ExportColumn::make('manager.name')
                ->label('Manager'),
            ExportColumn::make('start_date')
                ->label('Start Date'),
            ExportColumn::make('end_date')
                ->label('End Date'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('engagementBaseline.planItem.plan.name')
                ->label('Source Audit Plan'),
            ExportColumn::make('engagementBaseline.objective')
                ->label('Engagement Objective'),
            ExportColumn::make('engagementBaseline.scope')
                ->label('Engagement Scope'),
            ExportColumn::make('engagementBaseline.exclusions')
                ->label('Engagement Exclusions'),
            ExportColumn::make('engagementBaseline.team_user_ids')
                ->label('Engagement Team User IDs')
                ->state(fn (Audit $record): string => implode(',', $record->engagementBaseline?->team_user_ids ?? [])),
            ExportColumn::make('engagementBaseline.audit_snapshot')
                ->label('Engagement Audit Snapshot')
                ->state(fn (Audit $record): ?string => $record->engagementBaseline ? json_encode($record->engagementBaseline->audit_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null),
            ExportColumn::make('engagementBaseline.plan_snapshot')
                ->label('Engagement Plan Snapshot')
                ->state(fn (Audit $record): ?string => $record->engagementBaseline ? json_encode($record->engagementBaseline->plan_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null),
            ExportColumn::make('engagementBaseline.entity_assessment_snapshot')
                ->label('Engagement Entity Assessment Snapshot')
                ->state(fn (Audit $record): ?string => $record->engagementBaseline ? json_encode($record->engagementBaseline->entity_assessment_snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) : null),
            ExportColumn::make('engagementBaseline.launcher.name')
                ->label('Engagement Launched By'),
            ExportColumn::make('engagementBaseline.launched_at')
                ->label('Engagement Launched At'),
            ExportColumn::make('engagementBaseline.fingerprint')
                ->label('Engagement Baseline Fingerprint'),
            ExportColumn::make('department')
                ->label('Department')
                ->state(fn (Audit $record): ?string => $record->taxonomies->first(fn ($taxonomy): bool => $taxonomy->parent?->slug === 'department')?->name),
            ExportColumn::make('scope')
                ->label('Scope')
                ->state(fn (Audit $record): ?string => $record->taxonomies->first(fn ($taxonomy): bool => $taxonomy->parent?->slug === 'scope')?->name),
            ExportColumn::make('created_at')
                ->label('Created At'),
            ExportColumn::make('updated_at')
                ->label('Updated At'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['program', 'manager' => fn ($relation) => $relation->withTrashed(), 'engagementBaseline.launcher' => fn ($relation) => $relation->withTrashed(), 'engagementBaseline.planItem.plan', 'taxonomies.parent']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your audit export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
