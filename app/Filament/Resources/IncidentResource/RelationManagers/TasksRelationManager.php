<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Enums\IncidentTaskStatus;
use App\Incidents\IncidentDesk;
use App\Models\IncidentTask;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TasksRelationManager extends RelationManager
{
    protected static string $relationship = 'tasks';

    protected static ?string $title = 'Governed response tasks';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('assignee:id,name')->withCount('events'))
            ->columns([
                TextColumn::make('title')->wrap()->searchable(), TextColumn::make('phase')->badge(),
                TextColumn::make('status')->badge()->color(fn (string $state) => IncidentTaskStatus::tryFrom($state)?->getColor() ?? 'gray'),
                TextColumn::make('priority')->badge()->color(fn (string $state) => match ($state) {
                    'Critical' => 'danger', 'High' => 'warning', 'Medium' => 'info', default => 'gray',
                }), TextColumn::make('assignee.name')->placeholder('Unassigned'),
                TextColumn::make('due_date')->date()->placeholder('None'), TextColumn::make('events_count')->label('Events'),
                TextColumn::make('governance_status')->label('Governance')->badge()->color(fn (string $state) => $state === 'governed' ? 'success' : 'gray'),
            ])->recordActions([
                Action::make('record_event')->label('Record task event')->icon('heroicon-o-arrow-path')
                    ->visible(fn (IncidentTask $record): bool => $record->governed_at !== null && $this->canUpdate($record))
                    ->fillForm(fn (IncidentTask $record): array => [
                        'status' => $record->status, 'assignee_id' => $record->assignee_id, 'due_date' => $record->due_date,
                    ])->schema([
                        Select::make('status')->options(IncidentTaskStatus::class)->required(),
                        Select::make('assignee_id')->label('Assignee')->options(User::activeOptions())->searchable()
                            ->visible(fn (): bool => $this->canManageIncident()),
                        DatePicker::make('due_date')->visible(fn (): bool => $this->canManageIncident()),
                        Textarea::make('summary')->required()->maxLength(10000)->columnSpanFull(),
                    ])->action(fn (IncidentTask $record, array $data) => app(IncidentDesk::class)->recordTaskEvent(auth()->user(), $record, $data)),
                Action::make('inspect_history')->label('History')->icon('heroicon-o-clock')
                    ->visible(fn (IncidentTask $record): bool => $record->events_count > 0)
                    ->modalHeading(fn (IncidentTask $record): string => 'Governed history — '.$record->title)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (IncidentTask $record) {
                        $record->load('events.actor:id,name');

                        return view('filament.incident-task-history', ['task' => $record]);
                    }),
            ])->defaultSort('id');
    }

    private function canManageIncident(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ($actor->can('update', $this->getOwnerRecord()) || $actor->can('Manage Incident Tasks'));
    }

    private function canUpdate(IncidentTask $task): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ($this->canManageIncident() || $task->assignee_id === $actor->id);
    }
}
