<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Enums\RiskDomain;
use App\Enums\RiskIndicatorDirection;
use App\Enums\RiskIndicatorFrequency;
use App\Models\RiskIndicator;
use App\Services\RiskIndicatorManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RiskIndicatorsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskIndicators';

    protected static ?string $title = 'Key risk indicators';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->domain === RiskDomain::Operational;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['owner:id,name', 'latestObservation.observer:id,name'])->withCount('observations'))
            ->columns([
                TextColumn::make('code')->searchable(), TextColumn::make('name')->wrap()->searchable(), TextColumn::make('owner.name'),
                TextColumn::make('monitoring_status')->badge()->color(fn (string $state) => match ($state) {
                    'normal' => 'success', 'warning', 'awaiting_observation' => 'warning', 'critical', 'overdue' => 'danger', default => 'gray'
                }),
                TextColumn::make('schedule_status')->label('Schedule')->badge()->color(fn (string $state) => match ($state) {
                    'overdue' => 'danger', 'scheduled' => 'success', default => 'gray'
                }),
                TextColumn::make('latestObservation.observed_value')->label('Latest value')->suffix(fn (RiskIndicator $record) => ' '.$record->unit)->placeholder('None'),
                TextColumn::make('next_due_at')->dateTime()->sortable(), TextColumn::make('observations_count')->label('History'),
            ])->headerActions([
                Action::make('define')->label('Define indicator')->visible(fn () => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                    ->schema([
                        TextInput::make('code')->required()->maxLength(100), TextInput::make('name')->required()->maxLength(255),
                        Select::make('owner_id')->relationship('owner', 'name')->required(),
                        TextInput::make('unit')->required()->maxLength(50), Select::make('direction')->options(RiskIndicatorDirection::class)->required(),
                        TextInput::make('warning_threshold')->required(), TextInput::make('critical_threshold')->required(),
                        Select::make('frequency')->options(RiskIndicatorFrequency::class)->required(), DateTimePicker::make('next_due_at')->required(),
                        Textarea::make('description')->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(RiskIndicatorManager::class)->define($this->getOwnerRecord(), auth()->user(), $data)),
            ])->recordActions([
                Action::make('edit')->label('Edit definition')->icon('heroicon-o-pencil-square')
                    ->visible(fn () => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                    ->fillForm(fn (RiskIndicator $record) => $record->only(['owner_id', 'code', 'name', 'description', 'unit', 'direction', 'warning_threshold', 'critical_threshold', 'frequency', 'next_due_at', 'is_active']))
                    ->schema([
                        TextInput::make('code')->required()->maxLength(100), TextInput::make('name')->required()->maxLength(255), Select::make('owner_id')->relationship('owner', 'name')->required(),
                        TextInput::make('unit')->required()->maxLength(50), Select::make('direction')->options(RiskIndicatorDirection::class)->required(), TextInput::make('warning_threshold')->required(), TextInput::make('critical_threshold')->required(),
                        Select::make('frequency')->options(RiskIndicatorFrequency::class)->required(), DateTimePicker::make('next_due_at')->required(), Select::make('is_active')->options([1 => 'Active', 0 => 'Inactive'])->required(), Textarea::make('description')->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (RiskIndicator $record, array $data) => app(RiskIndicatorManager::class)->update($record, auth()->user(), $data)),
                Action::make('observe')->label('Record observation')->icon('heroicon-o-plus-circle')
                    ->visible(fn (RiskIndicator $record) => auth()->user() && (auth()->user()->can('Manage Risk Portfolio') || $record->owner_id === auth()->id() || $this->getOwnerRecord()->governanceProfile?->owner_id === auth()->id()))
                    ->schema([TextInput::make('observed_value')->required(), DateTimePicker::make('observed_at')->maxDate(now()), TextInput::make('source_reference')->maxLength(255), Textarea::make('notes')->maxLength(30000)])
                    ->action(fn (RiskIndicator $record, array $data) => app(RiskIndicatorManager::class)->observe($record, auth()->user(), $data)),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
