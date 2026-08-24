<?php

namespace App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers;

use App\Enums\SystemAuthorizationDecision;
use App\SystemAuthorization\SystemAuthorizationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DecisionsRelationManager extends RelationManager
{
    protected static string $relationship = 'decisions';

    protected static ?string $title = 'Authorization decisions';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('version'), TextColumn::make('decision')->badge(), TextColumn::make('authorizer.name')->label('Authorizer'), TextColumn::make('valid_until')->date(), TextColumn::make('decided_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([Action::make('decide')->visible(fn (): bool => auth()->user()?->can('decide', $this->getOwnerRecord()) === true)->schema([Select::make('decision')->options(collect(SystemAuthorizationDecision::cases())->mapWithKeys(fn ($d) => [$d->value => $d->getLabel()]))->required(), TagsInput::make('conditions'), Textarea::make('rationale')->required()->maxLength(30000), DatePicker::make('valid_until')->minDate(today()->addDay())])->action(fn (array $data) => app(SystemAuthorizationManager::class)->decide(auth()->user(), $this->getOwnerRecord(), $data))])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn ($record) => view('filament.system-authorization-evidence', ['record' => $record]))])->defaultSort('version', 'desc');
    }
}
