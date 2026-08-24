<?php

namespace App\Filament\Resources\GovernedModelResource\RelationManagers;

use App\Enums\ModelLifecycleStatus;
use App\Filament\Resources\GovernedModelResource\Pages\ListGovernedModels;
use App\ModelRisk\ModelRiskManager;
use App\Models\GovernedModelVersion;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Append-only model versions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($q) => $q->with('actor:id,name'))->columns([TextColumn::make('version')->sortable(), TextColumn::make('change_summary')->limit(80), TextColumn::make('actor.name')->label('Recorded by'), TextColumn::make('recorded_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([Action::make('revise')->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->lifecycle_status !== ModelLifecycleStatus::Retired)->schema([...ListGovernedModels::modelSchema(), Select::make('lifecycle_status')->options([ModelLifecycleStatus::Retired->value => ModelLifecycleStatus::Retired->getLabel()])])->fillForm(fn (): array => $this->getOwnerRecord()->only(['name', 'model_type', 'tier', 'owner_id', 'developer_id', 'intended_use', 'methodology', 'input_data', 'outputs', 'assumptions', 'limitations', 'usage_restrictions', 'implementation_reference', 'change_frequency', 'next_review_at']))->action(fn (array $data) => app(ModelRiskManager::class)->revise(auth()->user(), $this->getOwnerRecord(), $data))])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (GovernedModelVersion $record) => view('filament.model-risk-evidence', ['title' => 'Model version '.$record->version, 'snapshot' => $record->model_snapshot, 'summary' => $record->change_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
