<?php

namespace App\Filament\Resources\AssetResource\RelationManagers;

use App\Models\Risk;
use App\Services\RiskPortfolioContextManager;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class RisksRelationManager extends RelationManager
{
    protected static string $relationship = 'risks';

    protected static ?string $title = 'Risks';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('code')
                    ->sortable()
                    ->searchable()
                    ->badge()
                    ->color('primary')
                    ->wrap(),

                TextColumn::make('name')
                    ->sortable()
                    ->searchable()
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('residual_risk')
                    ->label('Residual Risk')
                    ->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Relate to Risk')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function (Builder $query) {
                        $query->select(['risks.id', 'risks.code', 'risks.name']);
                    })
                    ->recordTitle(function ($record) {
                        return strip_tags("({$record->code}) {$record->name}");
                    })
                    ->recordSelectSearchColumns(['code', 'name'])
                    ->using(function (BelongsToMany $relationship, Risk $record): void {
                        app(RiskPortfolioContextManager::class)->attachAsset($record, $relationship->getParent());
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.app.resources.risks.view', $record)),
                DetachAction::make()->using(function (Risk $record, Table $table): void {
                    app(RiskPortfolioContextManager::class)->detachAssets($record, [$table->getRelationship()->getParent()]);
                }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label('Detach from Asset')
                        ->using(function (DetachBulkAction $action, EloquentCollection $records, Table $table): void {
                            $asset = $table->getRelationship()->getParent();
                            $records->each(fn (Risk $risk) => app(RiskPortfolioContextManager::class)->detachAssets($risk, [$asset]));
                            $action->reportBulkProcessingSuccessfulRecordsCount($records->count());
                        }),
                ]),
            ]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
