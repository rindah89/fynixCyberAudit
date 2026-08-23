<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use App\Access\FileAccess;
use App\Enums\ThirdPartyRiskReviewOutcome;
use App\Models\FileAttachment;
use App\Models\User;
use App\Models\VendorRiskReview;
use App\Models\VendorRiskReviewEvidence;
use App\ThirdPartyRisk\ThirdPartyRiskManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class RiskReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'riskReviews';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'reviewer', 'decision', 'issue',
            'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]))->defaultSort('reviewed_at', 'desc')->columns([
            TextColumn::make('outcome')->badge()->color(fn ($state) => match ($state->value) {
                'satisfactory' => 'success', 'needs_action' => 'warning', 'terminate' => 'danger', default => 'gray',
            }), TextColumn::make('assessment_version')->label('Assessment'), TextColumn::make('reviewer.name')->label('Reviewer'),
            TextColumn::make('evidence_reference')->placeholder('None'), TextColumn::make('issue.status')->label('Issue')->badge()->placeholder('None'),
            TextColumn::make('evidence_count')->label('Governed evidence')
                ->state(fn (VendorRiskReview $record): int => $this->authorizedEvidence($record)->count()),
            TextColumn::make('next_review_at')->date(), TextColumn::make('reviewed_at')->dateTime(),
        ])->headerActions([
            Action::make('review')->label('Record periodic review')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => auth()->user()?->can('Manage Third Party Risk') ?? false)
                ->schema([
                    Select::make('outcome')->options(ThirdPartyRiskReviewOutcome::class)->required(),
                    Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(),
                    DatePicker::make('next_review_at')->required()->minDate(today()->addDay()),
                    TextInput::make('evidence_reference')->maxLength(255),
                    Select::make('evidence_attachment_ids')->label('Governed review evidence')
                        ->multiple()->maxItems(20)->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                        ->helperText('Optional accepted audit-evidence files you are authorized to access. Fynix retains content and SHA-256 snapshots.'),
                ])->action(fn (array $data) => app(ThirdPartyRiskManager::class)->review(
                    $this->getOwnerRecord(), auth()->user(), ThirdPartyRiskReviewOutcome::from($data['outcome']), $data,
                )),
        ])->recordActions([
            Action::make('inspect_evidence')->label('Evidence')->icon('heroicon-o-paper-clip')
                ->visible(fn (VendorRiskReview $record): bool => $this->authorizedEvidence($record)->isNotEmpty())
                ->modalHeading('Governed third-party review evidence')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(function (VendorRiskReview $record) {
                    $visibleRecord = clone $record;
                    $visibleRecord->setRelation('evidence', $this->authorizedEvidence($record));

                    return view('filament.vendor-risk-review-evidence', ['review' => $visibleRecord, 'actor' => auth()->user()]);
                }),
        ]);
    }

    /** @return Collection<int, VendorRiskReviewEvidence> */
    private function authorizedEvidence(VendorRiskReview $record): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return collect();
        }

        return $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values();
    }

    private function evidenceOptions(string $search): array
    {
        return FileAttachment::query()->eligibleGovernedEvidenceFor(auth()->user())
            ->where('file_name', 'like', '%'.addcslashes($search, '%_').'%')
            ->orderByDesc('id')->limit(50)->pluck('file_name', 'id')->all();
    }

    private function evidenceLabels(array $values): array
    {
        return FileAttachment::query()->eligibleGovernedEvidenceFor(auth()->user())
            ->whereKey($values)->pluck('file_name', 'id')->all();
    }
}
