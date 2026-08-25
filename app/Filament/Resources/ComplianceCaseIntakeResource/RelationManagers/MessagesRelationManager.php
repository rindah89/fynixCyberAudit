<?php

namespace App\Filament\Resources\ComplianceCaseIntakeResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseIntakeCorrespondenceManager;
use App\Enums\ComplianceCaseIntakeAudience;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MessagesRelationManager extends RelationManager
{
    protected static string $relationship = 'messages';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['actor:id,name,email', 'acknowledgement.recipient:id,name,email']))
            ->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('audience')->badge(),
                TextColumn::make('message')->limit(80)->searchable(), TextColumn::make('actor.name')->searchable(),
                TextColumn::make('recorded_at')->dateTime()->sortable(), TextColumn::make('fingerprint')->copyable(),
                TextColumn::make('acknowledgement.acknowledged_at')->label('Acknowledged')->dateTime()->placeholder('Not acknowledged')->sortable(),
                TextColumn::make('acknowledgement.fingerprint')->label('Acknowledgement fingerprint')->copyable()->toggleable(),
            ])->headerActions([
                Action::make('record')->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('audience')->options(ComplianceCaseIntakeAudience::class)->required(),
                    Textarea::make('message')->required()->maxLength(30000),
                ]))->action(fn (array $data) => app(ComplianceCaseIntakeCorrespondenceManager::class)->record(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                    ->modalContent(fn ($record) => view('filament.compliance-case-intake-message', [
                        'message' => $record->fresh()->load(['actor', 'acknowledgement.recipient']),
                    ])),
            ])->defaultSort('version');
    }
}
