<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseInterviewManager;
use App\Enums\ComplianceCaseInterviewStatus;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseInterview;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InterviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'interviews';

    protected static ?string $title = 'Governed interviews';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['subjectUser:id,name', 'interviewer:id,name', 'events.actor:id,name'])->withCount('events'))
            ->columns([
                TextColumn::make('subjectUser.name')->label('Internal subject')->placeholder(fn (ComplianceCaseInterview $record): string => $record->subject_reference ?: 'Not specified')->searchable(),
                TextColumn::make('interviewer.name')->searchable(), TextColumn::make('status')->badge(),
                TextColumn::make('scheduled_at')->dateTime()->sortable(), TextColumn::make('conducted_at')->dateTime()->placeholder('Not conducted'),
                TextColumn::make('events_count')->label('Events'),
            ])->headerActions([
                Action::make('schedule')->label('Schedule interview')->icon('heroicon-o-calendar-days')
                    ->visible(fn (): bool => $this->canManage() && in_array($this->getOwnerRecord()->status, [ComplianceCaseStatus::Triaged, ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true))
                    ->schema([
                        Select::make('subject_user_id')->label('Internal subject')->options(User::activeOptions())->searchable(),
                        TextInput::make('subject_reference')->label('External subject reference')->maxLength(255),
                        Select::make('interviewer_id')->options(User::permission('Investigate Compliance Cases')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        DateTimePicker::make('scheduled_at')->required(), TextInput::make('location')->maxLength(500),
                        Textarea::make('purpose')->required()->maxLength(30000)->columnSpanFull(),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(ComplianceCaseInterviewManager::class)->schedule(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('record')->label('Record decision')->icon('heroicon-o-pencil-square')
                    ->visible(fn (ComplianceCaseInterview $record): bool => $this->canManage() && $record->status === ComplianceCaseInterviewStatus::Scheduled)
                    ->fillForm(fn (ComplianceCaseInterview $record): array => [
                        'status' => $record->status->value, 'interviewer_id' => $record->interviewer_id,
                        'scheduled_at' => $record->scheduled_at, 'location' => $record->location, 'purpose' => $record->purpose,
                    ])
                    ->schema([
                        Select::make('status')->options(ComplianceCaseInterviewStatus::class)->required(),
                        Select::make('interviewer_id')->label('Interviewer')
                            ->options(User::permission('Investigate Compliance Cases')->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))
                            ->searchable()->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true)
                            ->dehydrated(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true),
                        DateTimePicker::make('scheduled_at'), DateTimePicker::make('conducted_at'),
                        TextInput::make('location')->maxLength(500), Textarea::make('purpose')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('summary')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('cancellation_reason')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (ComplianceCaseInterview $record, array $data) => app(ComplianceCaseInterviewManager::class)->record(auth()->user(), $this->getOwnerRecord(), $record, $data)),
                Action::make('history')->icon('heroicon-o-clock')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ComplianceCaseInterview $record) => view('filament.compliance-case-interview-history', [
                        'interview' => $record->load(['subjectUser:id,name,email', 'interviewer:id,name,email', 'events.actor:id,name,email']),
                    ])),
            ])->defaultSort('id', 'desc');
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('update', $this->getOwnerRecord()) === true;
    }
}
