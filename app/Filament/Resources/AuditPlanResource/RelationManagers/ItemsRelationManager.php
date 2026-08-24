<?php

namespace App\Filament\Resources\AuditPlanResource\RelationManagers;

use App\Filament\Exports\AuditPlanItemExporter;
use App\Models\AuditPlanItem;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Prioritized plan evidence';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['auditableEntity:id,code,name', 'assessment:id,version,residual_score,priority_band', 'audit:id,title', 'creator:id,name']))
            ->defaultSort('priority_rank', 'desc')->columns([
                TextColumn::make('priority_rank')->label('Rank')->sortable(), TextColumn::make('auditableEntity.code')->label('Entity'),
                TextColumn::make('assessment.version')->label('Assessment'), TextColumn::make('assessment.residual_score')->label('Residual'),
                TextColumn::make('assessment.priority_band')->label('Priority')->badge()->color(fn (?string $state): string => match ($state) {
                    'critical', 'high' => 'danger', 'medium' => 'warning', 'low' => 'success', default => 'gray'
                }),
                TextColumn::make('status')->badge(), TextColumn::make('planned_start_at')->date(), TextColumn::make('planned_end_at')->date(),
                TextColumn::make('audit.title')->label('Linked audit')->placeholder('Not scheduled'),
            ])->headerActions([ExportAction::make()->exporter(AuditPlanItemExporter::class)])
            ->recordActions([Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (AuditPlanItem $record) => view('filament.audit-plan-item', ['item' => $record]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
