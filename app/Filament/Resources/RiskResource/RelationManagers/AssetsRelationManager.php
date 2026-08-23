<?php

namespace App\Filament\Resources\RiskResource\RelationManagers;

use App\Models\Asset;
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

class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Assets';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['assetType', 'status']))
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('asset_tag')
                    ->label('Asset Tag')
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

                TextColumn::make('assetType.name')
                    ->label('Type')
                    ->sortable(),

                TextColumn::make('status.name')
                    ->label('Status')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Relate to Asset')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function (Builder $query) {
                        $query->select(['assets.id', 'assets.asset_tag', 'assets.name']);
                    })
                    ->recordTitle(function ($record) {
                        return $record->asset_tag
                            ? strip_tags("({$record->asset_tag}) {$record->name}")
                            : strip_tags($record->name);
                    })
                    ->recordSelectSearchColumns(['asset_tag', 'name'])
                    ->using(function (BelongsToMany $relationship, Asset $record): void {
                        app(RiskPortfolioContextManager::class)->attachAsset($relationship->getParent(), $record);
                    }),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.app.resources.assets.view', $record)),
                DetachAction::make()->using(function (Asset $record, Table $table): void {
                    app(RiskPortfolioContextManager::class)->detachAssets($table->getRelationship()->getParent(), [$record]);
                }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label('Detach from Risk')
                        ->using(function (DetachBulkAction $action, EloquentCollection $records, Table $table): void {
                            $count = app(RiskPortfolioContextManager::class)->detachAssets($table->getRelationship()->getParent(), $records);
                            $action->reportBulkProcessingSuccessfulRecordsCount($count);
                        }),
                ]),
            ]);
    }

    public function canCreate(): bool
    {
        return false;
    }
}
