<?php

namespace App\Filament\Resources;

use App\Enums\ResilienceCriticality;
use App\Filament\Resources\BusinessServiceResource\Pages\CreateBusinessService;
use App\Filament\Resources\BusinessServiceResource\Pages\EditBusinessService;
use App\Filament\Resources\BusinessServiceResource\Pages\ListBusinessServices;
use App\Filament\Resources\BusinessServiceResource\Pages\ViewBusinessService;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\ContinuityActivationsRelationManager;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\DependenciesRelationManager;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\ImpactAnalysesRelationManager;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\RecoveryExercisesRelationManager;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\RecoveryPlansRelationManager;
use App\Filament\Resources\BusinessServiceResource\RelationManagers\ResilienceIssuesRelationManager;
use App\Models\BusinessService;
use App\Support\Enterprise;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusinessServiceResource extends Resource
{
    protected static ?string $model = BusinessService::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static string|\UnitEnum|null $navigationGroup = 'Operational Resilience';

    protected static ?string $navigationLabel = 'Business Services';

    protected static ?string $recordTitleAttribute = 'name';

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('resilience');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('Business service')->columns(2)->schema([
            TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(255),
            TextInput::make('name')->required()->maxLength(255),
            Select::make('owner_id')->relationship('owner', 'name')->searchable()->preload()->required(),
            Select::make('criticality')->options(ResilienceCriticality::class)->required(),
            Select::make('status')->options(['active' => __('Active'), 'inactive' => __('Inactive')])->default('active')->required(),
            Textarea::make('description')->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(), TextColumn::make('name')->searchable()->wrap(),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('criticality')->badge(),
            TextColumn::make('readiness_status')->label('Readiness')->badge()->color(fn (string $state) => match ($state) {
                'ready' => 'success', 'inactive' => 'gray', 'action_required' => 'danger', default => 'warning',
            }), TextColumn::make('latest_exercise_outcome')->label('Latest exercise')->badge()->placeholder('None'),
        ])->filters([SelectFilter::make('criticality')->options(ResilienceCriticality::class)]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'latestApprovedImpactAnalysis',
            'latestApprovedRecoveryPlan.latestCompletedExercise',
            'resilienceIssues',
        ]);
        $user = auth()->user();
        if ($user && ! $user->can('Manage Resilience')) {
            $query->where('owner_id', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [ImpactAnalysesRelationManager::class, DependenciesRelationManager::class, RecoveryPlansRelationManager::class, RecoveryExercisesRelationManager::class, ContinuityActivationsRelationManager::class, ResilienceIssuesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBusinessServices::route('/'), 'create' => CreateBusinessService::route('/create'),
            'view' => ViewBusinessService::route('/{record}'), 'edit' => EditBusinessService::route('/{record}/edit'),
        ];
    }
}
