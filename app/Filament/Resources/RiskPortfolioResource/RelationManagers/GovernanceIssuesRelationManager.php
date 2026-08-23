<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GovernanceIssuesRelationManager extends RelationManager
{
    protected static string $relationship = 'governanceIssues';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['owner', 'remediationTask']))->columns([
            TextColumn::make('title')->wrap(), TextColumn::make('severity')->badge()->color(fn (string $state) => match ($state) {
                'critical', 'high' => 'danger', 'medium' => 'warning', default => 'gray',
            }), TextColumn::make('status')->badge(), TextColumn::make('owner.name'),
            TextColumn::make('remediationTask.title')->label('Remediation')->placeholder('Not linked'), TextColumn::make('created_at')->dateTime(),
        ]);
    }
}
