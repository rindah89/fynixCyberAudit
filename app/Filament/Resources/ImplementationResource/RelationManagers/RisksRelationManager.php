<?php

namespace App\Filament\Resources\ImplementationResource\RelationManagers;

use App\Models\Risk;
use App\Services\RiskPortfolioContextManager;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RisksRelationManager extends RelationManager
{
    protected static string $relationship = 'risks';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Associated Risks')
            ->description('Risks that this implementation helps to mitigate.')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('inherent_risk'),
                TextColumn::make('residual_risk'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Relate to Risk')
                    ->modalHeading('Relate to Risk')
                    ->using(function (BelongsToMany $relationship, Risk $record): void {
                        app(RiskPortfolioContextManager::class)->attachImplementation($record, $relationship->getParent());
                    }),
            ])
            ->recordActions([
                DetachAction::make()->using(function (Risk $record, Table $table): void {
                    app(RiskPortfolioContextManager::class)->detachImplementations($record, [$table->getRelationship()->getParent()]);
                }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label('Detach from Implementation')
                        ->using(function (DetachBulkAction $action, EloquentCollection $records, Table $table): void {
                            $implementation = $table->getRelationship()->getParent();
                            $records->each(fn (Risk $risk) => app(RiskPortfolioContextManager::class)->detachImplementations($risk, [$implementation]));
                            $action->reportBulkProcessingSuccessfulRecordsCount($records->count());
                        }),
                ]),
            ]);
    }
}
