<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Enums\IncidentLessonArea;
use App\Enums\IncidentLessonStatus;
use App\Enums\IncidentPhase;
use App\Incidents\IncidentLessonManager;
use App\Models\IncidentLesson;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LessonsRelationManager extends RelationManager
{
    protected static string $relationship = 'lessons';

    protected static ?string $title = 'Governed lessons learned';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('owner:id,name')->withCount('events'))
            ->columns([
                TextColumn::make('area')->badge(), TextColumn::make('observation')->wrap()->limit(100),
                TextColumn::make('recommendation')->wrap()->limit(100), TextColumn::make('owner.name'),
                TextColumn::make('target_date')->date()->placeholder('None'), TextColumn::make('status')->badge(),
                TextColumn::make('target_status')->label('Target')->badge()->color(fn (string $state) => match ($state) {
                    'overdue' => 'danger', 'pending' => 'warning', default => 'gray',
                }),
                TextColumn::make('events_count')->label('Events'),
            ])->headerActions([
                Action::make('register_lesson')->label('Register lesson')->icon('heroicon-o-light-bulb')
                    ->visible(fn (): bool => $this->canManage() && $this->getOwnerRecord()->phase === IncidentPhase::LessonsLearned)
                    ->schema($this->registerSchema())
                    ->action(fn (array $data) => app(IncidentLessonManager::class)->register(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('record_progress')->label('Record progress')->icon('heroicon-o-arrow-path')
                    ->visible(fn (IncidentLesson $record): bool => $record->status->allowedNext() !== [] && $this->canUpdate($record))
                    ->fillForm(fn (IncidentLesson $record): array => [
                        'status' => $record->status->value, 'area' => $record->area->value,
                        'observation' => $record->observation, 'recommendation' => $record->recommendation,
                        'owner_id' => $record->owner_id, 'target_date' => $record->target_date,
                    ])->schema($this->progressSchema())
                    ->action(fn (IncidentLesson $record, array $data) => app(IncidentLessonManager::class)->recordProgress(auth()->user(), $record, $data)),
                Action::make('inspect_history')->label('History')->icon('heroicon-o-clock')
                    ->visible(fn (IncidentLesson $record): bool => $record->events_count > 0)
                    ->modalHeading(fn (IncidentLesson $record): string => 'Lesson history — '.$record->area->getLabel())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (IncidentLesson $record) {
                        $record->load('events.actor:id,name');

                        return view('filament.incident-lesson-history', ['lesson' => $record]);
                    }),
            ])->defaultSort('id');
    }

    /** @return array<int, mixed> */
    private function registerSchema(): array
    {
        return [
            Select::make('area')->options(IncidentLessonArea::class)->required(),
            Textarea::make('observation')->required()->maxLength(30000)->columnSpanFull(),
            Textarea::make('recommendation')->required()->maxLength(30000)->columnSpanFull(),
            Select::make('owner_id')->label('Owner')->options(User::activeOptions())->searchable()->required(),
            DatePicker::make('target_date')->minDate(now()),
            Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private function progressSchema(): array
    {
        return [
            Select::make('status')->options(IncidentLessonStatus::class),
            Select::make('area')->options(IncidentLessonArea::class)->visible(fn (): bool => $this->canManage()),
            Textarea::make('observation')->maxLength(30000)->columnSpanFull()->visible(fn (): bool => $this->canManage()),
            Textarea::make('recommendation')->maxLength(30000)->columnSpanFull()->visible(fn (): bool => $this->canManage()),
            Select::make('owner_id')->label('Owner')->options(User::activeOptions())->searchable()->visible(fn (): bool => $this->canManage()),
            DatePicker::make('target_date')->minDate(now())->visible(fn (): bool => $this->canManage()),
            Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
        ];
    }

    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ($actor->can('update', $this->getOwnerRecord()) || $actor->can('Manage Incidents'));
    }

    private function canUpdate(IncidentLesson $lesson): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && ($this->canManage() || $lesson->owner_id === $actor->id);
    }
}
