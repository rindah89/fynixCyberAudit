<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseMilestoneManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseMilestone;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MilestonesRelationManager extends RelationManager
{
    protected static string $relationship = 'milestones';

    protected static ?string $title = 'Investigation milestones';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['owner:id,name,email', 'events', 'deliveries.recipient:id,name,email']))
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('owner.name')->label(__('Owner'))->searchable(),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('define')->label(__('Define milestone'))->icon('heroicon-o-flag')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true
                        && $this->getOwnerRecord()->status !== ComplianceCaseStatus::Closed)
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('description')->required()->maxLength(30000),
                        Select::make('owner_id')->label(__('Owner'))->options(User::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        DateTimePicker::make('due_at')->required(),
                        Toggle::make('required')->default(true),
                    ])->action(fn (array $data) => app(ComplianceCaseMilestoneManager::class)->define(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->label(__('Inspect evidence'))->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn (ComplianceCaseMilestone $record) => view('filament.compliance-case-milestone', [
                        'milestone' => $record->fresh()->load(['owner:id,name,email', 'events', 'deliveries.recipient:id,name,email']),
                    ])),
                Action::make('complete')->visible(fn (ComplianceCaseMilestone $record): bool => $record->status?->value === 'open'
                    && (auth()->id() === $record->owner_id || auth()->user()?->can('Manage Compliance Cases') === true))
                    ->schema([Textarea::make('summary')->required()->maxLength(30000)])
                    ->action(fn (ComplianceCaseMilestone $record, array $data) => app(ComplianceCaseMilestoneManager::class)->complete(auth()->user(), $record, $data)),
                Action::make('waive')->visible(fn (ComplianceCaseMilestone $record): bool => $record->status?->value === 'open'
                    && auth()->user()?->can('Manage Compliance Cases') === true
                    && auth()->id() !== $record->defined_by && auth()->id() !== $record->owner_id)
                    ->schema([Textarea::make('summary')->required()->maxLength(30000)])
                    ->action(fn (ComplianceCaseMilestone $record, array $data) => app(ComplianceCaseMilestoneManager::class)->waive(auth()->user(), $record, $data)),
            ])->defaultSort('version');
    }
}
