<?php

namespace App\Filament\Resources\PolicyResource\RelationManagers;

use App\Models\Implementation;
use App\PolicyCompliance\PolicyRevisionContextManager;
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

class ImplementationsRelationManager extends RelationManager
{
    protected static string $relationship = 'implementations';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['implementationOwner' => fn ($q) => $q->withTrashed()]))
            ->recordTitleAttribute('title')
            ->columns([
                TextColumn::make('title')
                    ->sortable()
                    ->searchable()
                    ->limit(50)
                    ->wrap(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        'Not Started' => 'danger',
                        'In Progress' => 'warning',
                        'Completed' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('controls_count')
                    ->label('Controls')
                    ->counts('controls')
                    ->badge()
                    ->color('info'),

                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->formatStateUsing(fn ($record): string => $record->implementationOwner?->displayName() ?? '')
                    ->sortable()
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Attach Implementation')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(function (Builder $query) {
                        $query->select(['implementations.id', 'title']);
                    })
                    ->recordTitle(function ($record) {
                        return strip_tags($record->title);
                    })
                    ->recordSelectSearchColumns(['title'])
                    ->using(fn (BelongsToMany $relationship, Implementation $record) => app(PolicyRevisionContextManager::class)
                        ->attachImplementation($relationship->getParent(), $record)),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => route('filament.app.resources.implementations.view', $record)),
                DetachAction::make()->using(fn (Implementation $record) => app(PolicyRevisionContextManager::class)
                    ->detachImplementations($this->getOwnerRecord(), [$record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DetachBulkAction::make()->label('Detach from Policy')
                        ->using(function (DetachBulkAction $action, EloquentCollection $records): void {
                            app(PolicyRevisionContextManager::class)->detachImplementations($this->getOwnerRecord(), $records);
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
