<?php

namespace App\Filament\Resources\AiSystemResource\RelationManagers;

use App\Enums\AiDecisionImpact;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class UseCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'useCases';

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->withGovernanceGraph();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->wrap(), TextColumn::make('owner.name')->label('Owner'), TextColumn::make('decision_impact')->badge()->color(fn (AiDecisionImpact $state) => match ($state) {
                AiDecisionImpact::Low => 'success', AiDecisionImpact::Medium => 'warning', AiDecisionImpact::High, AiDecisionImpact::Critical => 'danger',
            }),
            IconColumn::make('uses_personal_data')->boolean(), IconColumn::make('automated_decision')->boolean(),
            TextColumn::make('governance_status')->badge()->color(fn (string $state) => match ($state) {
                'approved' => 'success', 'suspended', 'action_required', 'monitoring_overdue', 'approval_expired', 'rejected' => 'danger', default => 'warning',
            }), TextColumn::make('next_monitoring_at')->date()->placeholder('Not scheduled'),
        ]);
    }
}
