<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsgGoalResource\Pages\ListEsgGoals;
use App\Filament\Resources\EsgGoalResource\Pages\ViewEsgGoal;
use App\Filament\Resources\EsgGoalResource\RelationManagers\KpisRelationManager;
use App\Models\EsgGoal;
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

class EsgGoalResource extends Resource
{
    protected static ?string $model = EsgGoal::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'ESG goals';

    protected static ?int $navigationSort = 50;

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
        return $record instanceof EsgGoal && auth()->user()?->can('view', $record->topic) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['topic:id,code,name,owner_id', 'owner:id,name', 'creator:id,name']);
        $actor = auth()->user();
        if ($actor && ! $actor->can('Read ESG') && ! $actor->can('Manage ESG') && ! $actor->can('Assess ESG') && ! $actor->can('Validate ESG Data') && ! $actor->can('Approve ESG Disclosures')) {
            $query->where(function (Builder $scope) use ($actor): void {
                $scope->where('owner_id', $actor->id)
                    ->orWhereHas('topic', fn (Builder $topic): Builder => $topic->where('owner_id', $actor->id))
                    ->orWhereHas('kpis', fn (Builder $kpi): Builder => $kpi->where('owner_id', $actor->id));
            });
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('title')->searchable(),
            TextColumn::make('topic.code')->label('Topic'), TextColumn::make('status')->badge(),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('target_date')->date(),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed ESG goal')->columns(3)->schema([
            TextEntry::make('code'), TextEntry::make('title')->columnSpan(2), TextEntry::make('topic.code'),
            TextEntry::make('status')->badge(), TextEntry::make('owner.name'), TextEntry::make('description')->columnSpanFull(),
            TextEntry::make('baseline_date')->date(), TextEntry::make('target_date')->date(), TextEntry::make('creator.name')->label('Created by'),
            TextEntry::make('topic_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
            TextEntry::make('assessment_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
            TextEntry::make('governed_at')->dateTime(), TextEntry::make('fingerprint')->columnSpan(2)->copyable(),
        ])]);
    }

    public static function getRelations(): array
    {
        return [KpisRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEsgGoals::route('/'), 'view' => ViewEsgGoal::route('/{record}')];
    }
}
