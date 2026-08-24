<?php

namespace App\Filament\Exports;

use App\Models\AuditPlanItem;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class AuditPlanItemExporter extends Exporter
{
    protected static ?string $model = AuditPlanItem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('plan.name')->label('Plan'), ExportColumn::make('plan.plan_year')->label('Year'),
            ExportColumn::make('auditableEntity.code')->label('Entity Code'), ExportColumn::make('assessment.version')->label('Assessment Version'),
            ExportColumn::make('assessment.residual_score')->label('Residual Score'), ExportColumn::make('assessment.priority_band')->label('Priority Band'),
            ExportColumn::make('priority_rank'), ExportColumn::make('status')->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('planned_start_at'), ExportColumn::make('planned_end_at'), ExportColumn::make('rationale'),
            ExportColumn::make('audit.title')->label('Linked Audit'),
            ExportColumn::make('entity_assessment_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
            ExportColumn::make('creator.name')->label('Created By'), ExportColumn::make('created_at'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['plan:id,name,plan_year', 'auditableEntity:id,code', 'assessment:id,version,residual_score,priority_band', 'audit:id,title', 'creator:id,name']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your risk-based audit-plan export contains '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).'.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
