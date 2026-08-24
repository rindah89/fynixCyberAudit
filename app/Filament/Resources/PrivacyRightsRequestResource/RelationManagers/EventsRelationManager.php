<?php

namespace App\Filament\Resources\PrivacyRightsRequestResource\RelationManagers;

use App\Enums\PrivacyRightsRequestStatus;
use App\Models\PrivacyRightsRequestEvent;
use App\Models\User;
use App\Privacy\PrivacyRightsRequestManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    protected static ?string $title = 'Append-only fulfillment history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))->columns([
            TextColumn::make('version'), TextColumn::make('to_status')->label('Status')->badge(), TextColumn::make('summary')->limit(80),
            TextColumn::make('actor.name')->label('Actor'), TextColumn::make('recorded_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->headerActions([
            Action::make('transition')->label('Record fulfillment decision')->icon('heroicon-o-arrow-right-circle')
                ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->status->allowedNext() !== [])
                ->schema([
                    Select::make('status')->options(fn (): array => collect($this->getOwnerRecord()->status->allowedNext())->mapWithKeys(fn (PrivacyRightsRequestStatus $s): array => [$s->value => $s->getLabel()])->all())->required(),
                    Select::make('assigned_to')->label('Reassign handler')->searchable()->options(fn (): array => User::permission(['Handle Privacy Rights', 'Manage Privacy Rights'])->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all())->visible(fn (): bool => auth()->user()?->can('Manage Privacy Rights') === true),
                    Textarea::make('identity_verification_summary')->maxLength(30000)->columnSpanFull(), Textarea::make('response_summary')->maxLength(30000)->columnSpanFull(),
                    Textarea::make('decision_basis')->maxLength(30000)->columnSpanFull(), Textarea::make('delivery_reference')->maxLength(2000)->columnSpanFull(),
                    Textarea::make('summary')->label('Decision rationale')->required()->maxLength(10000)->columnSpanFull(),
                ])->action(fn (array $data) => app(PrivacyRightsRequestManager::class)->transition(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([
            Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (PrivacyRightsRequestEvent $record) => view('filament.privacy-rights-request-event', ['record' => $record->load('actor:id,name')])),
        ])->defaultSort('version', 'desc');
    }
}
