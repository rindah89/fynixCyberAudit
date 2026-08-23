<?php

namespace App\Filament\Resources;

use App\Enums\PolicyObligationFrequency;
use App\Filament\Resources\PolicyObligationResource\Pages\CreatePolicyObligation;
use App\Filament\Resources\PolicyObligationResource\Pages\EditPolicyObligation;
use App\Filament\Resources\PolicyObligationResource\Pages\ListPolicyObligations;
use App\Filament\Resources\PolicyObligationResource\Pages\ViewPolicyObligation;
use App\Filament\Resources\PolicyObligationResource\RelationManagers\AttestationsRelationManager;
use App\Models\PolicyObligation;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

class PolicyObligationResource extends Resource
{
    protected static ?string $model = PolicyObligation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Policy Obligations';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Obligation')
                ->columns(2)
                ->schema([
                    Select::make('policy_id')
                        ->relationship('policy', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('control_id')
                        ->relationship('control', 'title')
                        ->searchable()
                        ->preload(),
                    TextInput::make('code')
                        ->required()
                        ->maxLength(255)
                        ->unique(
                            PolicyObligation::class,
                            'code',
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('policy_id', $get('policy_id')),
                        ),
                    TextInput::make('title')->required()->maxLength(255),
                    Textarea::make('description')->columnSpanFull(),
                    Select::make('owner_id')
                        ->relationship('owner', 'name')
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('frequency')
                        ->options(PolicyObligationFrequency::class)
                        ->default(PolicyObligationFrequency::Annual)
                        ->required(),
                    DateTimePicker::make('next_due_at')->required(),
                    Toggle::make('is_active')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('policy.name')->label('Policy')->searchable(),
                TextColumn::make('owner.name')->label('Owner'),
                TextColumn::make('frequency')->badge(),
                TextColumn::make('compliance_status')
                    ->label('Compliance')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'compliant' => 'success',
                        'non_compliant', 'overdue' => 'danger',
                        'due' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('next_due_at')->dateTime()->sortable(),
                TextColumn::make('last_attested_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('frequency')->options(PolicyObligationFrequency::class),
                SelectFilter::make('is_active')->options(['1' => 'Active', '0' => 'Inactive']),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user && ! $user->can('List Policies')) {
            $query->where(function (Builder $query) use ($user): void {
                $query->where('owner_id', $user->id)
                    ->orWhereHas('policy', fn (Builder $policy) => $policy->where('owner_id', $user->id));
            });
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [AttestationsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPolicyObligations::route('/'),
            'create' => CreatePolicyObligation::route('/create'),
            'view' => ViewPolicyObligation::route('/{record}'),
            'edit' => EditPolicyObligation::route('/{record}/edit'),
        ];
    }
}
