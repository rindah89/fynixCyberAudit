<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Models\ComplianceCaseAccessGrant;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccessGrantsRelationManager extends RelationManager
{
    protected static string $relationship = 'accessGrants';

    protected static ?string $title = 'Need-to-know access grants';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['grantee:id,name,email', 'grantor:id,name,email', 'revocation']))
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('grantee.name')->label(__('Grantee'))->searchable(),
                TextColumn::make('purpose')->limit(40),
                TextColumn::make('starts_at')->dateTime()->sortable(),
                TextColumn::make('ends_at')->dateTime()->sortable(),
                TextColumn::make('revocation.id')->label(__('Status'))->formatStateUsing(fn ($state): string => $state ? __('Revoked') : __('Active'))->badge(),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('grant')->label(__('Grant access'))->icon('heroicon-o-key')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true)
                    ->schema([
                        Select::make('grantee_id')->label(__('Grantee'))->options(User::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Textarea::make('purpose')->required()->maxLength(30000),
                        DateTimePicker::make('starts_at')->required(),
                        DateTimePicker::make('ends_at')->required(),
                    ])->action(fn (array $data) => app(ComplianceCaseAccessGrantManager::class)->grant(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('revoke')->visible(fn (ComplianceCaseAccessGrant $record): bool => $record->revocation === null
                    && auth()->user()?->can('Manage Compliance Cases') === true)
                    ->schema([Textarea::make('summary')->required()->maxLength(30000)])
                    ->action(fn (ComplianceCaseAccessGrant $record, array $data) => app(ComplianceCaseAccessGrantManager::class)->revoke(auth()->user(), $record, $data)),
            ])->defaultSort('version');
    }
}
