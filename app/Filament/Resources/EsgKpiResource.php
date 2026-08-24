<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsgKpiResource\Pages\ListEsgKpis;
use App\Filament\Resources\EsgKpiResource\Pages\ViewEsgKpi;
use App\Filament\Resources\EsgKpiResource\RelationManagers\ObservationsRelationManager;
use App\Models\EsgKpi;
use App\Models\EsgMaterialTopic;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EsgKpiResource extends Resource
{
    protected static ?string $model = EsgKpi::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'ESG KPIs';

    protected static ?int $navigationSort = 51;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('esg_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('esg_management') && auth()->user()?->can('viewAny', EsgMaterialTopic::class) === true;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EsgKpi && auth()->user()?->can('view', $record->goal->topic) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['goal.topic:id,code,name,owner_id', 'owner:id,name', 'creator:id,name', 'latestObservation.observer:id,name']);
        $actor = auth()->user();
        if ($actor && ! $actor->can('Read ESG') && ! $actor->can('Manage ESG') && ! $actor->can('Assess ESG') && ! $actor->can('Validate ESG Data') && ! $actor->can('Approve ESG Disclosures')) {
            $query->where(function (Builder $scope) use ($actor): void {
                $scope->where('owner_id', $actor->id)
                    ->orWhereHas('goal', fn (Builder $goal): Builder => $goal->where('owner_id', $actor->id))
                    ->orWhereHas('goal.topic', fn (Builder $topic): Builder => $topic->where('owner_id', $actor->id));
            });
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable(), TextColumn::make('goal.code')->label('Goal'),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('last_status')->badge(), TextColumn::make('monitoring_status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())->color(fn (string $state): string => match ($state) {
                'target_met' => 'success', 'target_not_met', 'overdue' => 'warning', 'inactive' => 'gray', default => 'info',
            }),
            TextColumn::make('next_due_at')->dateTime(),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed ESG KPI')->columns(3)->schema([
            TextEntry::make('code'), TextEntry::make('name')->columnSpan(2), TextEntry::make('goal.code'),
            TextEntry::make('owner.name'), TextEntry::make('monitoring_status')->badge()->formatStateUsing(fn (string $state): string => str($state)->headline()->toString())->color(fn (string $state): string => match ($state) {
                'target_met' => 'success', 'target_not_met', 'overdue' => 'warning', 'inactive' => 'gray', default => 'info',
            }), TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('unit'), TextEntry::make('direction'), TextEntry::make('frequency_days'),
            TextEntry::make('baseline_value'), TextEntry::make('target_value'), TextEntry::make('next_due_at')->dateTime(),
            TextEntry::make('measurement_method')->columnSpanFull(), TextEntry::make('source_reference')->columnSpanFull()->placeholder('Not supplied'),
            TextEntry::make('goal_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
            TextEntry::make('fingerprint')->columnSpanFull()->copyable(),
        ])]);
    }

    public static function getRelations(): array
    {
        return [ObservationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEsgKpis::route('/'), 'view' => ViewEsgKpi::route('/{record}')];
    }
}
