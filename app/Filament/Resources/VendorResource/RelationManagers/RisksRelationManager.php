<?php

namespace App\Filament\Resources\VendorResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RisksRelationManager extends RelationManager
{
    protected static string $relationship = 'risks';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()->wrap(),
            TextColumn::make('domain')->badge()->color('gray'), TextColumn::make('residual_risk')->label('Residual score')->badge(),
            TextColumn::make('status')->badge(),
        ]);
    }
}
