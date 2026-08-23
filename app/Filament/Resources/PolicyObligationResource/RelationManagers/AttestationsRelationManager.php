<?php

namespace App\Filament\Resources\PolicyObligationResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
                TextColumn::make('attested_at')->dateTime(),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
