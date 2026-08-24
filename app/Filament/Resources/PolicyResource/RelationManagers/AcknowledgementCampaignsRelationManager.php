<?php

namespace App\Filament\Resources\PolicyResource\RelationManagers;

use App\Models\PolicyAcknowledgementCampaign;
use App\Models\User;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AcknowledgementCampaignsRelationManager extends RelationManager
{
    protected static string $relationship = 'acknowledgementCampaigns';

    protected static ?string $title = 'Acknowledgement campaigns';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('launcher:id,name')
            ->withCount(['assignments', 'assignments as acknowledged_count' => fn ($query) => $query->has('acknowledgement')]))
            ->defaultSort('launched_at', 'desc')
            ->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('title')->searchable(),
                TextColumn::make('campaign_status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                    'complete' => 'success', 'active' => 'warning', 'overdue' => 'danger', default => 'gray',
                }),
                TextColumn::make('acknowledged_count')->label('Acknowledged'),
                TextColumn::make('assignments_count')->label('Audience'),
                TextColumn::make('due_at')->dateTime()->sortable(),
                TextColumn::make('launcher.name')->label('Launched by'),
            ])->headerActions([
                Action::make('launch')->label('Launch campaign')->icon('heroicon-o-megaphone')
                    ->visible(fn (): bool => auth()->user()?->can('Update Policies') || $this->getOwnerRecord()->owner_id === auth()->id())
                    ->schema([
                        TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('instructions')->maxLength(10000)->columnSpanFull(),
                        DateTimePicker::make('due_at')->required()->minDate(now()),
                        Select::make('audience_user_ids')->label('Audience')->multiple()->required()->searchable()->preload()
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')),
                    ])->action(fn (array $data) => app(PolicyAcknowledgementManager::class)->launch($this->getOwnerRecord(), auth()->user(), $data)),
            ])->recordActions([
                Action::make('close')->label('Close')->color('danger')->requiresConfirmation()
                    ->visible(fn (PolicyAcknowledgementCampaign $record): bool => ! $record->closed_at && (auth()->user()?->can('Update Policies') || $this->getOwnerRecord()->owner_id === auth()->id()))
                    ->action(fn (PolicyAcknowledgementCampaign $record) => app(PolicyAcknowledgementManager::class)->close($record, auth()->user())),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
