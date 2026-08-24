<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Enums\IncidentNotificationAudience;
use App\Enums\IncidentNotificationStatus;
use App\Incidents\IncidentNotificationManager;
use App\Models\IncidentNotification;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NotificationsRelationManager extends RelationManager
{
    protected static string $relationship = 'notifications';

    protected static ?string $title = 'Governed notification decisions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->withCount('events'))
            ->columns([
                TextColumn::make('audience')->badge(), TextColumn::make('recipient')->wrap()->searchable(),
                TextColumn::make('framework')->placeholder('Not specified')->wrap(),
                TextColumn::make('status')->badge(),
                TextColumn::make('deadline_status')->label('Deadline')->badge()->color(fn (string $state) => match ($state) {
                    'overdue' => 'danger', 'pending' => 'warning', default => 'gray',
                }),
                TextColumn::make('deadline_at')->dateTime()->placeholder('None'),
                TextColumn::make('events_count')->label('Events'),
            ])->headerActions([
                Action::make('register_notification')->label('Register notification decision')->icon('heroicon-o-megaphone')
                    ->visible(fn (): bool => $this->canManage())
                    ->schema($this->registerSchema())
                    ->action(fn (array $data) => app(IncidentNotificationManager::class)->register(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('record_decision')->label('Record decision')->icon('heroicon-o-pencil-square')
                    ->visible(fn (IncidentNotification $record): bool => $this->canManage() && $record->status->allowedNext() !== [])
                    ->fillForm(fn (IncidentNotification $record): array => [
                        'framework' => $record->framework, 'recipient' => $record->recipient,
                        'deadline_at' => $record->deadline_at, 'delivery_reference' => $record->delivery_reference,
                    ])->schema($this->decisionSchema())
                    ->action(fn (IncidentNotification $record, array $data) => app(IncidentNotificationManager::class)->recordDecision(auth()->user(), $record, $data)),
                Action::make('inspect_history')->label('History')->icon('heroicon-o-clock')
                    ->visible(fn (IncidentNotification $record): bool => $record->events_count > 0)
                    ->modalHeading(fn (IncidentNotification $record): string => 'Notification history — '.$record->audience->getLabel())
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (IncidentNotification $record) {
                        $record->load('events.actor:id,name');

                        return view('filament.incident-notification-history', ['notification' => $record]);
                    }),
            ])->defaultSort('id');
    }

    /** @return array<int, mixed> */
    private function registerSchema(): array
    {
        return [
            Select::make('audience')->options(IncidentNotificationAudience::class)->required(),
            TextInput::make('framework')->maxLength(255), TextInput::make('recipient')->required()->maxLength(255),
            DateTimePicker::make('deadline_at'), Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
        ];
    }

    /** @return array<int, mixed> */
    private function decisionSchema(): array
    {
        return [
            Select::make('status')->options(IncidentNotificationStatus::class),
            TextInput::make('framework')->maxLength(255), TextInput::make('recipient')->required()->maxLength(255),
            DateTimePicker::make('deadline_at'), TextInput::make('delivery_reference')->maxLength(2000),
            Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
        ];
    }

    private function canManage(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $actor->can('Manage Breach Notifications') && $this->getOwnerRecord()->governed_at !== null;
    }
}
