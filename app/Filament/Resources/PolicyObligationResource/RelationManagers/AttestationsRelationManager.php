<?php

namespace App\Filament\Resources\PolicyObligationResource\RelationManagers;

use App\Access\FileAccess;
use App\Models\PolicyAttestation;
use App\Models\PolicyAttestationEvidence;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class AttestationsRelationManager extends RelationManager
{
    protected static string $relationship = 'attestations';

    protected static ?string $title = 'Attestation history';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('attested_at', 'desc')
            ->columns([
                TextColumn::make('outcome')->badge(),
                TextColumn::make('statement')->wrap(),
                TextColumn::make('evidence_reference')->label('Evidence'),
                TextColumn::make('policyException.name')->label('Exception'),
                TextColumn::make('attestor.name')->label('Attested by'),
                TextColumn::make('evidence_count')->label('Governed evidence')
                    ->state(fn (PolicyAttestation $record): int => $this->authorizedEvidence($record)->count()),
                TextColumn::make('attested_at')->dateTime(),
            ])->recordActions([
                Action::make('inspect_evidence')->label('Evidence')->icon('heroicon-o-paper-clip')
                    ->visible(fn (PolicyAttestation $record): bool => $this->authorizedEvidence($record)->isNotEmpty())
                    ->modalHeading('Governed policy-attestation evidence')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (PolicyAttestation $record) {
                        $visibleRecord = clone $record;
                        $visibleRecord->setRelation('evidence', $this->authorizedEvidence($record));

                        return view('filament.policy-attestation-evidence', ['attestation' => $visibleRecord, 'actor' => auth()->user()]);
                    }),
            ])->modifyQueryUsing(fn ($query) => $query->with([
                'attestor', 'policyException',
                'evidence.attachment.audit.members',
                'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
            ]));
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** @return Collection<int, PolicyAttestationEvidence> */
    private function authorizedEvidence(PolicyAttestation $record): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return collect();
        }

        return $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values();
    }
}
