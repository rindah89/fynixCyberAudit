<?php

namespace App\Filament\Resources\RiskResource\RelationManagers;

use App\Filament\Resources\ImplementationResource;
use App\Models\Implementation;
use App\Services\RiskPortfolioContextManager;
use Filament\Actions\AttachAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DetachAction;
use Filament\Actions\DetachBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ImplementationsRelationManager extends RelationManager
{
    protected static string $relationship = 'implementations';

    public function form(Schema $schema): Schema
    {
        return ImplementationResource::getForm($schema);
    }

    public function table(Table $table): Table
    {
        $table = ImplementationResource::getTable($table);
        $table->modifyQueryUsing(fn (Builder $query) => $query->with(['latestCompletedAudit', 'implementationOwner' => fn ($q) => $q->withTrashed()]));
        $table->headerActions([
            CreateAction::make()
                ->label('New Implementation')
                ->using(function (array $data): Implementation {
                    $implementation = new Implementation;
                    $implementation->fill($data)->save();
                    app(RiskPortfolioContextManager::class)->attachImplementation($this->getOwnerRecord(), $implementation);

                    return $implementation;
                }),
            AttachAction::make()
                ->label('Add Existing Implementation')
                ->preloadRecordSelect()
                ->recordSelectOptionsQuery(function (Builder $query) {
                    $query->select(['implementations.id', 'code', 'title']);
                })
                ->recordTitle(function ($record) {
                    return strip_tags("({$record->code}) {$record->title}");
                })
                ->recordSelectSearchColumns(['code', 'title'])
                ->using(function (BelongsToMany $relationship, Implementation $record): void {
                    app(RiskPortfolioContextManager::class)->attachImplementation($relationship->getParent(), $record);
                }),
        ]);
        $table->recordActions([
            ViewAction::make()->hidden(),
            EditAction::make()
                ->modalHeading('Edit Implementation'),
            DetachAction::make()->using(function (Implementation $record, Table $table): void {
                app(RiskPortfolioContextManager::class)->detachImplementations($table->getRelationship()->getParent(), [$record]);
            }),
        ]);
        $table->toolbarActions([
            BulkActionGroup::make([
                DetachBulkAction::make()->label('Detach from Risk')
                    ->using(function (DetachBulkAction $action, EloquentCollection $records, Table $table): void {
                        $count = app(RiskPortfolioContextManager::class)->detachImplementations($table->getRelationship()->getParent(), $records);
                        $action->reportBulkProcessingSuccessfulRecordsCount($count);
                    }),
            ]),
        ]);

        return $table;
    }
}
