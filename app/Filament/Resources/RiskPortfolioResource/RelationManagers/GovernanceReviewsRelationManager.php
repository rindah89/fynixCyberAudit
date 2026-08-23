<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Access\FileAccess;
use App\Models\RiskGovernanceReview;
use App\Models\RiskGovernanceReviewEvidence;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class GovernanceReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'governanceReviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'reviewer', 'issue',
            'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]))->defaultSort('reviewed_at', 'desc')->columns([
            TextColumn::make('decision')->badge()->color(fn ($state) => $state->value === 'accepted' ? 'success' : 'danger'),
            TextColumn::make('reviewer.name')->label('Reviewer'), TextColumn::make('residual_score_snapshot')->label('Residual')->badge(),
            TextColumn::make('appetite_threshold_snapshot')->label('Appetite'), TextColumn::make('evidence_reference')->placeholder('None'),
            TextColumn::make('evidence_count')->label('Governed evidence')
                ->state(fn (RiskGovernanceReview $record): int => $this->authorizedEvidence($record)->count()),
            TextColumn::make('issue.status')->label('Issue')->badge()->placeholder('None'), TextColumn::make('next_review_at')->date(),
            TextColumn::make('reviewed_at')->dateTime(),
        ])->recordActions([
            Action::make('inspect_evidence')->label('Evidence')->icon('heroicon-o-paper-clip')
                ->visible(fn (RiskGovernanceReview $record): bool => $this->authorizedEvidence($record)->isNotEmpty())
                ->modalHeading('Governed risk-review evidence')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(function (RiskGovernanceReview $record) {
                    $visibleRecord = clone $record;
                    $visibleRecord->setRelation('evidence', $this->authorizedEvidence($record));

                    return view('filament.risk-governance-review-evidence', ['review' => $visibleRecord, 'actor' => auth()->user()]);
                }),
        ]);
    }

    /** @return Collection<int, RiskGovernanceReviewEvidence> */
    private function authorizedEvidence(RiskGovernanceReview $record): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return collect();
        }

        return $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values();
    }
}
