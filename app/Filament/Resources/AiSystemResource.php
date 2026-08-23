<?php

namespace App\Filament\Resources;

use App\Enums\AiDecisionImpact;
use App\Enums\AiLifecycleStatus;
use App\Filament\Resources\AiSystemResource\Pages\CreateAiSystem;
use App\Filament\Resources\AiSystemResource\Pages\EditAiSystem;
use App\Filament\Resources\AiSystemResource\Pages\ListAiSystems;
use App\Filament\Resources\AiSystemResource\Pages\ViewAiSystem;
use App\Filament\Resources\AiSystemResource\RelationManagers\UseCasesRelationManager;
use App\Models\AiSystem;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class AiSystemResource extends Resource
{
    protected static ?string $model = AiSystem::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static string|\UnitEnum|null $navigationGroup = 'AI Governance';

    protected static ?string $navigationLabel = 'AI Systems';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('AI system inventory')->columns(2)->schema([
            TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(255), TextInput::make('name')->required()->maxLength(255),
            Select::make('owner_id')->relationship('owner', 'name')->searchable()->preload()->required(),
            Select::make('vendor_id')->relationship('vendor', 'name')->searchable()->preload(),
            Select::make('application_id')->relationship('application', 'name')->searchable()->preload(),
            TextInput::make('provider_name')->required()->maxLength(255), TextInput::make('model_name')->required()->maxLength(255),
            TextInput::make('deployment_type')->required()->maxLength(255),
            Select::make('lifecycle_status')->options(AiLifecycleStatus::class)->required(),
            Select::make('criticality')->options(AiDecisionImpact::class)->required(), DatePicker::make('next_review_at')->required(),
            Textarea::make('intended_purpose')->required()->columnSpanFull(), Textarea::make('prohibited_uses')->columnSpanFull(),
            Textarea::make('human_oversight')->required()->columnSpanFull(), TagsInput::make('data_categories')->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(), TextColumn::make('name')->searchable()->wrap(),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('provider_name')->label('Provider'), TextColumn::make('model_name')->label('Model'),
            TextColumn::make('lifecycle_status')->badge()->color(fn (AiLifecycleStatus $state) => match ($state) {
                AiLifecycleStatus::Active => 'success', AiLifecycleStatus::Pilot => 'warning', AiLifecycleStatus::Proposed => 'info',
                AiLifecycleStatus::Suspended => 'danger', AiLifecycleStatus::Retired => 'gray',
            }), TextColumn::make('criticality')->badge()->color(fn (AiDecisionImpact $state) => match ($state) {
                AiDecisionImpact::Low => 'success', AiDecisionImpact::Medium => 'warning', AiDecisionImpact::High, AiDecisionImpact::Critical => 'danger',
            }),
            TextColumn::make('governance_status')->label('Governance')->badge()->color(fn (string $state) => match ($state) {
                'governed' => 'success', 'retired' => 'gray',
                'action_required', 'suspended', 'review_overdue', 'monitoring_overdue', 'approval_expired', 'rejected' => 'danger',
                default => 'warning',
            }), TextColumn::make('next_review_at')->date()->sortable(),
        ])->filters([SelectFilter::make('lifecycle_status')->options(AiLifecycleStatus::class), SelectFilter::make('criticality')->options(AiDecisionImpact::class)]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['owner', 'vendor', 'application', 'useCases' => fn (Relation $query) => $query->withGovernanceGraph()]);
        $user = auth()->user();
        if ($user && ! $user->can('Manage AI Governance')) {
            $query->where('owner_id', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [UseCasesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListAiSystems::route('/'), 'create' => CreateAiSystem::route('/create'), 'view' => ViewAiSystem::route('/{record}'), 'edit' => EditAiSystem::route('/{record}/edit')];
    }
}
