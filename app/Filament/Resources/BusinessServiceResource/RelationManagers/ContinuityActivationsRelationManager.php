<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use App\Enums\ContinuityActivationStatus;
use App\Models\ContinuityActivation;
use App\OperationalResilience\ContinuityActivationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContinuityActivationsRelationManager extends RelationManager
{
    protected static string $relationship = 'continuityActivations';

    protected static ?string $title = 'Continuity activation and recovery history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('started_at')->dateTime()->sortable(), TextColumn::make('recoveryPlan.title')->label('Plan')->wrap(),
            TextColumn::make('disruption_summary')->limit(80)->wrap(), TextColumn::make('status')->badge(),
            TextColumn::make('outcome')->badge()->placeholder('Pending'), TextColumn::make('actual_recovery_time_minutes')->label('Actual RTO')->suffix(' min')->placeholder('Pending'),
            TextColumn::make('events_count')->counts('events')->label('Events'),
        ])->recordActions([
            Action::make('inspect')->label('Inspect history')->icon('heroicon-o-clock')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (ContinuityActivation $record) => view('filament.continuity-activation', ['activation' => $record->load('events.recorder:id,name')])),
            Action::make('transition')->label('Advance recovery')->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (ContinuityActivation $record): bool => $record->status->allowedNext() !== [] && (auth()->user()?->can('Manage Resilience') ?? false))
                ->schema([
                    Select::make('status')->options(fn (ContinuityActivation $record): array => collect($record->status->allowedNext())->mapWithKeys(fn (ContinuityActivationStatus $status) => [$status->value => $status->getLabel()])->all())->required(),
                    Textarea::make('summary')->required()->maxLength(10000),
                    TextInput::make('actual_recovery_point_minutes')->numeric()->minValue(0)->maxValue(525600)->helperText('Required when service is restored.'),
                ])->action(fn (ContinuityActivation $record, array $data) => app(ContinuityActivationManager::class)->transition(auth()->user(), $record, $data)),
        ])->modifyQueryUsing(fn ($query) => $query->with(['recoveryPlan:id,title', 'activator:id,name', 'events.recorder:id,name']))->defaultSort('started_at', 'desc');
    }
}
