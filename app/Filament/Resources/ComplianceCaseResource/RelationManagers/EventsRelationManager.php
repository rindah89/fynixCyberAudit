<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseEvent;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Append-only case history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))
            ->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('event_type')->label('Event'),
                TextColumn::make('summary')->limit(60), TextColumn::make('actor.name')->label('Actor'),
                TextColumn::make('recorded_at')->dateTime()->sortable(), TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('record')->label('Record case decision')->icon('heroicon-o-pencil-square')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true
                        && $this->getOwnerRecord()->status !== ComplianceCaseStatus::Closed)
                    ->schema([
                        Select::make('status')->options(fn (): array => collect($this->getOwnerRecord()->status->allowedNext())
                            ->mapWithKeys(fn (ComplianceCaseStatus $status): array => [$status->value => $status->getLabel()])->all()),
                        Select::make('assigned_to')->label('Investigator')->searchable()->options(fn (): array => User::permission('Investigate Compliance Cases')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all())
                            ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true),
                        DateTimePicker::make('due_at')->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true),
                        Textarea::make('triage_summary')->maxLength(30000)->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true)->columnSpanFull(),
                        Textarea::make('investigation_summary')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('resolution_summary')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('closure_summary')->maxLength(30000)->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true)->columnSpanFull(),
                        Textarea::make('summary')->label('Decision rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(ComplianceCaseManager::class)->record(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ComplianceCaseEvent $record) => view('filament.compliance-case-event', ['record' => $record->load('actor:id,name')])),
            ])->defaultSort('version', 'desc');
    }
}
