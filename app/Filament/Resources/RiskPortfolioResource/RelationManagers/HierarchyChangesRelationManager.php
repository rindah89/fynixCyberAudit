<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class HierarchyChangesRelationManager extends RelationManager
{
    protected static string $relationship = 'hierarchyChanges';

    protected static ?string $title = 'Hierarchy history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->check() && (auth()->user()->can('Manage Risk Portfolio') || auth()->user()->can('Read Risks'));
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['previousParent', 'parent', 'changedBy']))->defaultSort('changed_at', 'desc')->columns([
            TextColumn::make('previousParent.code')->label('Previous parent')->placeholder('Root'),
            TextColumn::make('parent.code')->label('New parent')->placeholder('Root'),
            TextColumn::make('changedBy.name')->label('Changed by'),
            TextColumn::make('changed_at')->dateTime()->sortable(),
        ])->headerActions([])->recordActions([])->toolbarActions([]);
    }
}
