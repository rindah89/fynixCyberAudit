<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Access\FileAccess;
use App\Enums\IncidentPhase;
use App\Incidents\IncidentDesk;
use App\Models\FileAttachment;
use App\Models\IncidentPhaseTransition;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PhaseTransitionsRelationManager extends RelationManager
{
    protected static string $relationship = 'phaseTransitions';

    protected static ?string $title = 'Governed phase history';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('actor:id,name')->withCount('evidence'))
            ->columns([
                TextColumn::make('from_phase')->label('From')->badge()->placeholder('Created'),
                TextColumn::make('to_phase')->label('To')->badge(),
                TextColumn::make('summary')->wrap()->limit(120),
                TextColumn::make('actor.name')->label('Actor'),
                TextColumn::make('transitioned_at')->dateTime()->sortable(),
                TextColumn::make('fingerprint')->copyable()->limit(12),
            ])
            ->headerActions([
                Action::make('advance_phase')->label('Advance phase')->icon('heroicon-o-arrow-right')
                    ->visible(fn (): bool => $this->getOwnerRecord()->governed_at !== null
                        && auth()->user()?->can('update', $this->getOwnerRecord()) === true
                        && $this->getOwnerRecord()->phase !== IncidentPhase::LessonsLearned)
                    ->schema([
                        Select::make('phase')->options(IncidentPhase::class)->required(),
                        Select::make('evidence_attachment_ids')->label('Governed phase-decision evidence')->multiple()->maxItems(20)->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                            ->helperText('Optional accepted audit files you can access. Fynix retains bounded copies and SHA-256 snapshots.'),
                        Textarea::make('summary')->required()->maxLength(10000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(IncidentDesk::class)->advancePhase(
                        auth()->user(), $this->getOwnerRecord(), IncidentPhase::from($data['phase']), $data['summary'], $data['evidence_attachment_ids'] ?? [],
                    )),
            ])
            ->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (IncidentPhaseTransition $record) {
                        $record->load(['actor:id,name', 'evidence.linkedBy:id,name', 'evidence.attachment.audit.members',
                            'evidence.attachment.dataRequestResponse.dataRequest.audit.members']);
                        $record->setRelation('evidence', $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
                            && app(FileAccess::class)->canDownloadFileAttachment(auth()->user(), $evidence->attachment))->values());

                        return view('filament.incident-phase-transition', ['transition' => $record]);
                    }),
            ])
            ->defaultSort('id');
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
