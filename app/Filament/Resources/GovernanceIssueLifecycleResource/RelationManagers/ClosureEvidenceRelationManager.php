<?php

namespace App\Filament\Resources\GovernanceIssueLifecycleResource\RelationManagers;

use App\Access\FileAccess;
use App\Models\GovernanceIssueClosureEvidence;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClosureEvidenceRelationManager extends RelationManager
{
    protected static string $relationship = 'closureEvidence';

    protected static ?string $title = 'Governed closure evidence';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'linkedBy:id,name',
            'attachment.audit.members',
            'attachment.dataRequestResponse.dataRequest.audit.members',
        ]))->defaultSort('linked_at', 'desc')->columns([
            TextColumn::make('file_name_snapshot')->label('File')->wrap(),
            TextColumn::make('file_size_snapshot')->label('Bytes')->numeric(),
            TextColumn::make('sha256')->label('SHA-256')->copyable()->limit(16),
            TextColumn::make('audit_id_snapshot')->label('Audit ID'),
            TextColumn::make('data_request_id_snapshot')->label('Request ID'),
            TextColumn::make('data_request_response_id_snapshot')->label('Response ID'),
            TextColumn::make('response_status_snapshot')->label('Response state')->badge()->color('success'),
            TextColumn::make('linkedBy.name')->label('Linked by'),
            TextColumn::make('linked_at')->dateTime()->sortable(),
        ])->headerActions([])->recordActions([
            Action::make('download')->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (GovernanceIssueClosureEvidence $record): bool => $record->attachment !== null
                    && auth()->user() !== null
                    && app(FileAccess::class)->canDownloadFileAttachment(auth()->user(), $record->attachment))
                ->url(fn (GovernanceIssueClosureEvidence $record): string => route('governance-closure-evidence.download', $record)),
        ])->toolbarActions([]);
    }
}
