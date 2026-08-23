<?php

namespace App\Filament\Resources;

use App\Enums\ControlTestFrequency;
use App\Enums\ControlTestMetricType;
use App\Enums\ControlTestOperator;
use App\Filament\Resources\ControlTestDefinitionResource\Pages\CreateControlTestDefinition;
use App\Filament\Resources\ControlTestDefinitionResource\Pages\EditControlTestDefinition;
use App\Filament\Resources\ControlTestDefinitionResource\Pages\ListControlTestDefinitions;
use App\Filament\Resources\ControlTestDefinitionResource\Pages\ViewControlTestDefinition;
use App\Filament\Resources\ControlTestDefinitionResource\RelationManagers\ExecutionsRelationManager;
use App\Models\ControlTestDefinition;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ControlTestDefinitionResource extends Resource
{
    protected static ?string $model = ControlTestDefinition::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-beaker';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Control Testing';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Control test')->columns(2)->schema([
                Select::make('control_id')->relationship('control', 'title')->searchable()->preload()->required(),
                Select::make('implementation_id')
                    ->relationship('implementation', 'title')
                    ->searchable()->preload()
                    ->helperText('Optional; must already be mapped to the selected control.'),
                TextInput::make('code')->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('name')->required()->maxLength(255),
                Textarea::make('description')->columnSpanFull(),
                Textarea::make('instructions')->columnSpanFull(),
                Select::make('owner_id')->relationship('owner', 'name')->searchable()->preload()->required(),
                Select::make('metric_type')->options(ControlTestMetricType::class)->required(),
                Select::make('operator')->options(ControlTestOperator::class)->required(),
                TextInput::make('expected_value')->required()->maxLength(255),
                Select::make('frequency')->options(ControlTestFrequency::class)->default('monthly')->required(),
                DateTimePicker::make('next_run_at')->required(),
                Toggle::make('is_active')->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('name')->searchable()->wrap(),
            TextColumn::make('control.title')->label('Control')->wrap(),
            TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('monitoring_status')->label('Schedule')->badge()->color(fn (string $state) => match ($state) {
                'due' => 'warning', 'inactive' => 'gray', default => 'info',
            }),
            TextColumn::make('last_outcome')->badge()->color(fn ($state) => match ($state?->value ?? $state) {
                'passed' => 'success', 'failed' => 'danger', default => 'gray',
            }),
            TextColumn::make('next_run_at')->dateTime()->sortable(),
        ])->filters([
            SelectFilter::make('frequency')->options(ControlTestFrequency::class),
            SelectFilter::make('is_active')->options(['1' => 'Active', '0' => 'Inactive']),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();
        if ($user && ! $user->can('List Controls')) {
            $query->where(fn (Builder $q) => $q->where('owner_id', $user->id)
                ->orWhereHas('control', fn (Builder $control) => $control->where('control_owner_id', $user->id)));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [ExecutionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListControlTestDefinitions::route('/'),
            'create' => CreateControlTestDefinition::route('/create'),
            'view' => ViewControlTestDefinition::route('/{record}'),
            'edit' => EditControlTestDefinition::route('/{record}/edit'),
        ];
    }
}
