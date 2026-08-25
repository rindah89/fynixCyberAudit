<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseEvidenceManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseEvidenceSubmission;
use App\Models\FileAttachment;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EvidenceSubmissionsRelationManager extends RelationManager
{
    protected static string $relationship = 'evidenceSubmissions';

    protected static ?string $title = 'Governed case evidence';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['actor:id,name', 'evidence.attachment.audit.members', 'evidence.attachment.dataRequestResponse.dataRequest.audit.members']))
            ->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('summary')->limit(60),
                TextColumn::make('actor.name')->label('Recorded by'), TextColumn::make('recorded_at')->dateTime(),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('submit')->label('Add governed evidence')->icon('heroicon-o-paper-clip')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true
                        && $this->getOwnerRecord()->status !== ComplianceCaseStatus::Closed)
                    ->schema([
                        Select::make('evidence_attachment_ids')->label('Accepted audit evidence')->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceOptions('', array_map('intval', $values)))->required()->maxItems(20),
                        Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(ComplianceCaseEvidenceManager::class)->submit(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (ComplianceCaseEvidenceSubmission $record) {
                        $record->load(['actor:id,name,email', 'evidence.attachment.audit.members', 'evidence.attachment.dataRequestResponse.dataRequest.audit.members']);
                        $visible = app(ComplianceCaseEvidenceManager::class)->visibleSubmissions(collect([$record]), auth()->user())->first();

                        return view('filament.compliance-case-evidence', ['record' => $visible]);
                    }),
            ])->defaultSort('version', 'desc');
    }

    /** @param list<int> $ids @return array<int,string> */
    private function evidenceOptions(string $search, array $ids = []): array
    {
        $query = FileAttachment::query()->eligibleGovernedEvidenceFor(auth()->user());
        if ($ids !== []) {
            $query->whereKey($ids);
        } elseif ($search !== '') {
            $query->where('file_name', 'like', "%{$search}%");
        }

        return $query->latest('id')->limit(100)->get()->mapWithKeys(fn (FileAttachment $attachment): array => [
            $attachment->id => ($attachment->file_name ?: basename($attachment->file_path))." · attachment #{$attachment->id}",
        ])->all();
    }
}
