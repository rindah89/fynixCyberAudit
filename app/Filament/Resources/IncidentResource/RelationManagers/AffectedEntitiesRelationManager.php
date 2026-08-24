<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Enums\IncidentAffectedEntityType;
use App\Incidents\IncidentAffectedEntityManager;
use App\Models\Application;
use App\Models\Asset;
use App\Models\Control;
use App\Models\IncidentAffectedEntity;
use App\Models\Risk;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AffectedEntitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'affectedEntities';

    protected static ?string $title = 'Governed affected entities';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('linkedBy:id,name'))
            ->columns([
                TextColumn::make('entity_type')->badge(),
                TextColumn::make('entity_snapshot.name')->label('Entity')->placeholder(fn (IncidentAffectedEntity $record) => data_get($record->entity_snapshot, 'title') ?? data_get($record->entity_snapshot, 'code') ?? '#'.$record->entity_id_snapshot),
                TextColumn::make('impact_summary')->limit(100)->wrap(),
                TextColumn::make('linkedBy.name')->label('Linked by'),
                TextColumn::make('linked_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('link')->label('Link affected entity')->icon('heroicon-o-link')
                    ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->governed_at !== null)
                    ->schema([
                        Select::make('entity_type')->options(IncidentAffectedEntityType::class)->required()->live(),
                        Select::make('entity_id')->label('Entity')->required()->searchable()
                            ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->entityOptions($get('entity_type'), $search))
                            ->getOptionLabelsUsing(fn (Get $get, array $values): array => $this->entityLabels($get('entity_type'), $values)),
                        Textarea::make('impact_summary')->required()->maxLength(30000)->columnSpanFull(),
                        Textarea::make('control_failure_note')->maxLength(30000)->columnSpanFull()
                            ->helperText('Required when the affected entity is a control.'),
                    ])->action(fn (array $data) => app(IncidentAffectedEntityManager::class)->link(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (IncidentAffectedEntity $record) => view('filament.incident-affected-entity', ['record' => $record])),
            ])->defaultSort('id');
    }

    private function entityOptions(?string $type, string $search): array
    {
        $enum = IncidentAffectedEntityType::tryFrom((string) $type);
        if ($enum === null) {
            return [];
        }
        [$class, $labelFields] = $this->entityConfig($enum);

        return $class::query()->where(function ($query) use ($labelFields, $search): void {
            foreach ($labelFields as $field) {
                $query->orWhere($field, 'like', '%'.addcslashes($search, '%_').'%');
            }
        })->limit(50)->get()->filter(fn ($record): bool => auth()->user()?->can('view', $record) === true)
            ->mapWithKeys(fn ($record): array => [$record->getKey() => $this->entityLabel($record, $labelFields)])->all();
    }

    private function entityLabels(?string $type, array $ids): array
    {
        $enum = IncidentAffectedEntityType::tryFrom((string) $type);
        if ($enum === null) {
            return [];
        }
        [$class, $labelFields] = $this->entityConfig($enum);

        return $class::query()->whereKey($ids)->get()->filter(fn ($record): bool => auth()->user()?->can('view', $record) === true)
            ->mapWithKeys(fn ($record): array => [$record->getKey() => $this->entityLabel($record, $labelFields)])->all();
    }

    /** @return array{class-string, list<string>} */
    private function entityConfig(IncidentAffectedEntityType $type): array
    {
        return match ($type) {
            IncidentAffectedEntityType::Asset => [Asset::class, ['asset_tag', 'name']],
            IncidentAffectedEntityType::Application => [Application::class, ['name']],
            IncidentAffectedEntityType::Vendor => [Vendor::class, ['name']],
            IncidentAffectedEntityType::Control => [Control::class, ['code', 'title']],
            IncidentAffectedEntityType::Risk => [Risk::class, ['code', 'name']],
        };
    }

    private function entityLabel($record, array $fields): string
    {
        return collect($fields)->map(fn (string $field) => $record->getAttribute($field))->filter()->implode(' — ');
    }
}
