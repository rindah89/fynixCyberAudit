<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseConflictManager;
use App\Enums\ComplianceCaseConflictDecision;
use App\Models\ComplianceCaseConflictDeclaration;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConflictsRelationManager extends RelationManager
{
    protected static string $relationship = 'conflictDeclarations';

    protected static ?string $title = 'Conflict and recusal register';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['subject:id,name,email', 'declarer:id,name,email', 'decision.decider:id,name,email']))
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('subject.name')->label(__('Subject'))->searchable(),
                TextColumn::make('nature')->limit(40),
                TextColumn::make('decision.decision')->label(__('Decision'))->badge()->placeholder(__('Pending')),
                TextColumn::make('declared_at')->dateTime()->sortable(),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('declare')->label(__('Declare conflict'))->icon('heroicon-o-exclamation-triangle')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true)
                    ->schema([
                        Select::make('subject_user_id')->label(__('Subject'))->options(User::query()->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Textarea::make('nature')->required()->maxLength(30000),
                        Textarea::make('rationale')->required()->maxLength(30000),
                    ])->action(fn (array $data) => app(ComplianceCaseConflictManager::class)->declare(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('decide')->visible(fn (ComplianceCaseConflictDeclaration $record): bool => $record->decision === null
                    && auth()->user()?->can('Manage Compliance Cases') === true
                    && auth()->id() !== $record->subject_user_id && auth()->id() !== $record->declared_by)
                    ->schema([
                        Select::make('decision')->options(ComplianceCaseConflictDecision::class)->required(),
                        Textarea::make('summary')->required()->maxLength(30000),
                    ])->action(fn (ComplianceCaseConflictDeclaration $record, array $data) => app(ComplianceCaseConflictManager::class)->decide(auth()->user(), $record, $data)),
            ])->defaultSort('version');
    }
}
