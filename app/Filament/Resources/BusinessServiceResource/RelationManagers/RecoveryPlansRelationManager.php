<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use App\Enums\RecoveryPlanStatus;
use App\Models\RecoveryPlan;
use App\OperationalResilience\ContinuityActivationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RecoveryPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'recoveryPlans';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version'), TextColumn::make('title')->wrap(), TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('status')->badge(), TextColumn::make('review_due_at')->date(), TextColumn::make('exercises_count')->counts('exercises')->label('Exercises'),
        ])->recordActions([
            Action::make('activate')->label('Activate for disruption')->icon('heroicon-o-bolt')
                ->visible(fn (RecoveryPlan $record): bool => $record->status === RecoveryPlanStatus::Approved && (auth()->user()?->can('Manage Resilience') ?? false))
                ->schema([
                    Textarea::make('disruption_summary')->required()->maxLength(10000),
                    Textarea::make('business_impact')->required()->maxLength(30000),
                    DateTimePicker::make('started_at')->required()->default(now()),
                ])->action(fn (RecoveryPlan $record, array $data) => app(ContinuityActivationManager::class)->activate(auth()->user(), $record, $data)),
        ])->defaultSort('version', 'desc');
    }
}
