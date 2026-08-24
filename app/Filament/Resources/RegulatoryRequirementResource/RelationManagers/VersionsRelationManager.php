<?php

namespace App\Filament\Resources\RegulatoryRequirementResource\RelationManagers;

use App\Models\RegulatoryRequirementVersion;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Append-only requirement versions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('publisher:id,name'))->defaultSort('version', 'desc')->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('change_type')->badge()->color('gray'),
            TextColumn::make('status')->badge(),
            TextColumn::make('title'), TextColumn::make('effective_at')->date(), TextColumn::make('publisher.name')->label('Published by'),
            TextColumn::make('published_at')->dateTime(),
        ])->recordActions([
            Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (RegulatoryRequirementVersion $record) => view('filament.regulatory-requirement-version', ['version' => $record])),
        ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
