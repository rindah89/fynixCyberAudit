<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\Models\ComplianceCaseActionIssue;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ActionIssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'actionIssues';

    protected static ?string $title = 'Governed action remediation';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'owner:id,name', 'opener:id,name', 'lifecycle.remediationTask', 'lifecycle.verifier:id,name',
            'lifecycle.transitions.actor:id,name', 'lifecycle.closureEvidence.linkedBy:id,name',
        ]))->columns([
            TextColumn::make('title')->searchable(), TextColumn::make('severity')->badge(),
            TextColumn::make('lifecycle.status')->label('Lifecycle')->badge(),
            TextColumn::make('owner.name')->label('Owner')->searchable(), TextColumn::make('opened_at')->dateTime()->sortable(),
            TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->recordActions([
            Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (ComplianceCaseActionIssue $record) => view('filament.compliance-case-action-issue', [
                    'record' => $record->load([
                        'owner:id,name,email', 'opener:id,name,email', 'event.actor:id,name,email',
                        'lifecycle.remediationTask', 'lifecycle.verifier:id,name,email', 'lifecycle.closer:id,name,email',
                        'lifecycle.transitions.actor:id,name,email', 'lifecycle.closureEvidence.linkedBy:id,name,email',
                    ]),
                ])),
        ])->defaultSort('id', 'desc');
    }
}
