<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseCommunicationManager;
use App\Enums\ComplianceCaseCommunicationDecisionType;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CommunicationsRelationManager extends RelationManager
{
    protected static string $relationship = 'communicationDecisions';

    protected static ?string $title = 'Communication decisions';

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('version')->sortable(),
            TextColumn::make('audience')->badge(),
            TextColumn::make('decision')->badge(),
            TextColumn::make('deadline_at')->dateTime()->placeholder(__('None')),
            TextColumn::make('external_reference')->placeholder(__('None'))->limit(24),
            TextColumn::make('decided_at')->dateTime()->sortable(),
            TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->headerActions([
            Action::make('record')->label(__('Record decision'))->icon('heroicon-o-megaphone')
                ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true)
                ->schema([
                    TextInput::make('audience')->required()->maxLength(40),
                    Textarea::make('purpose')->required()->maxLength(30000),
                    Select::make('decision')->options(ComplianceCaseCommunicationDecisionType::class)->required(),
                    DateTimePicker::make('deadline_at'),
                    TextInput::make('external_reference')->maxLength(255),
                ])->action(fn (array $data) => app(ComplianceCaseCommunicationManager::class)->record(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->defaultSort('version');
    }
}
