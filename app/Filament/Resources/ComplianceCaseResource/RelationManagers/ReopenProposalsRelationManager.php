<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseReopenManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseReopenProposal;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ReopenProposalsRelationManager extends RelationManager
{
    protected static string $relationship = 'reopenProposals';

    protected static ?string $title = 'Reopen proposals';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('review'))
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('summary')->limit(40),
                TextColumn::make('review.decision')->label(__('Review'))->badge()->placeholder(__('Pending')),
                TextColumn::make('proposed_at')->dateTime()->sortable(),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('propose')->label(__('Propose reopen'))->icon('heroicon-o-arrow-path')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true
                        && $this->getOwnerRecord()->status === ComplianceCaseStatus::Closed)
                    ->schema([Textarea::make('summary')->required()->maxLength(30000)])
                    ->action(fn (array $data) => app(ComplianceCaseReopenManager::class)->propose(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('review')->visible(fn (ComplianceCaseReopenProposal $record): bool => $record->review === null
                    && auth()->user()?->can('Manage Compliance Cases') === true
                    && auth()->id() !== $record->proposed_by)
                    ->schema([
                        Select::make('decision')->options(['approved' => __('Approved'), 'rejected' => __('Rejected')])->required(),
                        Textarea::make('summary')->required()->maxLength(30000),
                    ])->action(fn (ComplianceCaseReopenProposal $record, array $data) => app(ComplianceCaseReopenManager::class)->review(auth()->user(), $record, $data)),
            ])->defaultSort('version');
    }
}
