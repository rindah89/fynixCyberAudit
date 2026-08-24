<?php

namespace App\Filament\Resources\PrivacyProcessingActivityResource\RelationManagers;

use App\Enums\PrivacyActivityStatus;
use App\Filament\Resources\PrivacyProcessingActivityResource\Pages\ListPrivacyProcessingActivities;
use App\Models\PrivacyActivityVersion;
use App\Privacy\PrivacyManagementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Append-only activity versions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('actor:id,name'))->columns([TextColumn::make('version')->sortable(), TextColumn::make('change_summary')->limit(80), TextColumn::make('actor.name')->label('Recorded by'), TextColumn::make('recorded_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([
            Action::make('revise')->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->status !== PrivacyActivityStatus::Retired)->schema([...ListPrivacyProcessingActivities::activitySchema(), Select::make('status')->options(PrivacyActivityStatus::class)])->fillForm(fn (): array => $this->getOwnerRecord()->only(['name', 'owner_id', 'purpose', 'lawful_basis', 'data_subject_categories', 'personal_data_categories', 'special_category_data', 'recipient_categories', 'systems_and_vendors', 'processing_locations', 'cross_border_transfer', 'transfer_safeguards', 'retention_period', 'security_measures', 'source_reference', 'next_review_at', 'status']))->action(fn (array $data) => app(PrivacyManagementManager::class)->revise(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (PrivacyActivityVersion $record) => view('filament.privacy-evidence', ['title' => 'Activity version '.$record->version, 'snapshot' => $record->activity_snapshot, 'summary' => $record->change_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
