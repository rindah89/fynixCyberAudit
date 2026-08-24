<?php

namespace App\Filament\Resources\EsgMaterialTopicResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    public function table(Table $t): Table
    {
        return $t->columns([TextColumn::make('version'), TextColumn::make('change_summary')->limit(80), TextColumn::make('actor.name')->label('Recorded by'), TextColumn::make('recorded_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable()])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn ($record) => view('filament.esg-materiality-evidence', ['title' => 'Topic version '.$record->version, 'snapshot' => $record->topic_snapshot, 'summary' => $record->change_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
