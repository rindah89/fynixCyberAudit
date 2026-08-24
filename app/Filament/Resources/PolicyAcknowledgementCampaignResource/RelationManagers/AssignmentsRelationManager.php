<?php

namespace App\Filament\Resources\PolicyAcknowledgementCampaignResource\RelationManagers;

use App\Filament\Exports\PolicyAcknowledgementAssignmentExporter;
use App\Models\PolicyAcknowledgementAssignment;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssignmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assignments';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['user:id,name,email', 'campaign', 'delivery', 'reminders', 'escalation.recipient:id,name,email', 'acknowledgement.acknowledger:id,name,email']))
            ->columns([
                TextColumn::make('user.name')->label('User')->searchable(), TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('acknowledgement_status')->label('Status')->badge()->color(fn (string $state): string => match ($state) {
                    'acknowledged' => 'success', 'pending' => 'warning', 'overdue' => 'danger', default => 'gray',
                }),
                TextColumn::make('assigned_at')->dateTime()->sortable(),
                TextColumn::make('delivery.delivered_at')->label('Notification delivered')->dateTime()->placeholder('Not delivered')->sortable(),
                TextColumn::make('reminders_count')->label('Reminders')->counts('reminders'),
                TextColumn::make('escalation.delivered_at')->label('Escalated')->dateTime()->placeholder('Not escalated'),
                TextColumn::make('acknowledgement.acknowledged_at')->label('Acknowledged at')->dateTime()->placeholder('Not acknowledged'),
            ])->headerActions([ExportAction::make()->exporter(PolicyAcknowledgementAssignmentExporter::class)])
            ->recordActions([Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                ->modalHeading('Policy acknowledgement evidence')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (PolicyAcknowledgementAssignment $record) => view('filament.policy-acknowledgement', ['assignment' => $record]))]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
