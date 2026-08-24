<?php

namespace App\Filament\Resources;

use App\Enums\ModelGovernanceStatus;
use App\Filament\Resources\GovernedModelResource\Pages\ListGovernedModels;
use App\Filament\Resources\GovernedModelResource\Pages\ViewGovernedModel;
use App\Filament\Resources\GovernedModelResource\RelationManagers\ValidationsRelationManager;
use App\Filament\Resources\GovernedModelResource\RelationManagers\VersionsRelationManager;
use App\Models\GovernedModel;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class GovernedModelResource extends Resource
{
    protected static ?string $model = GovernedModel::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calculator';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Model risk';

    protected static ?int $navigationSort = 47;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('model_risk_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('model_risk_management') && auth()->user()?->can('viewAny', GovernedModel::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', GovernedModel::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['owner:id,name', 'developer:id,name', 'latestVersion', 'latestValidation']);
        $a = auth()->user();
        if ($a && ! $a->can('Read Model Risk') && ! $a->can('Manage Model Risk') && ! $a->can('Validate Models')) {
            $q->where(fn ($x) => $x->where('owner_id', $a->id)->orWhere('developer_id', $a->id));
        }

        return $q;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('code')->searchable()->sortable(), TextColumn::make('name')->searchable(), TextColumn::make('model_type')->sortable(), TextColumn::make('tier')->badge()->sortable(), TextColumn::make('lifecycle_status')->badge()->sortable(), TextColumn::make('validation_state')->badge()->color(fn (string $state): string => ModelGovernanceStatus::tryFrom($state)?->getColor() ?? 'gray'), TextColumn::make('owner.name')->label('Owner'), TextColumn::make('next_review_at')->date()->sortable()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed model')->columns(3)->schema([TextEntry::make('code'), TextEntry::make('name')->columnSpan(2), TextEntry::make('model_type'), TextEntry::make('tier')->badge(), TextEntry::make('lifecycle_status')->badge(), TextEntry::make('validation_state')->badge()->color(fn (string $state): string => ModelGovernanceStatus::tryFrom($state)?->getColor() ?? 'gray'), TextEntry::make('owner.name')->label('Owner'), TextEntry::make('developer.name')->label('Developer'), TextEntry::make('intended_use')->columnSpanFull(), TextEntry::make('methodology')->columnSpanFull(), TextEntry::make('input_data')->listWithLineBreaks(), TextEntry::make('outputs')->listWithLineBreaks(), TextEntry::make('assumptions')->listWithLineBreaks(), TextEntry::make('limitations')->listWithLineBreaks(), TextEntry::make('usage_restrictions')->listWithLineBreaks(), TextEntry::make('implementation_reference')->placeholder('Not supplied'), TextEntry::make('change_frequency'), TextEntry::make('next_review_at')->date()])]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, ValidationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListGovernedModels::route('/'), 'view' => ViewGovernedModel::route('/{record}')];
    }
}
