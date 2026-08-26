<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseRetentionManager;
use App\Enums\ComplianceCaseDispositionDecision;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseRetentionClassification;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RetentionRelationManager extends RelationManager
{
    protected static string $relationship = 'retentionClassifications';

    protected static ?string $title = 'Retention and disposition';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('disposition'))
            ->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('policy_reference')->searchable(),
                TextColumn::make('classification'),
                TextColumn::make('starts_on')->date(),
                TextColumn::make('ends_on')->date(),
                TextColumn::make('disposition.decision')->label(__('Disposition'))->badge()->placeholder(__('Pending')),
                TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('classify')->label(__('Classify retention'))->icon('heroicon-o-archive-box')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Compliance Cases') === true
                        && $this->getOwnerRecord()->status === ComplianceCaseStatus::Closed)
                    ->schema([
                        TextInput::make('policy_reference')->required()->maxLength(255),
                        TextInput::make('classification')->required()->maxLength(100),
                        DatePicker::make('starts_on')->required(),
                        DatePicker::make('ends_on')->required(),
                        Textarea::make('rationale')->required()->maxLength(30000),
                    ])->action(fn (array $data) => app(ComplianceCaseRetentionManager::class)->classify(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('disposition')->visible(fn (ComplianceCaseRetentionClassification $record): bool => $record->disposition === null
                    && auth()->user()?->can('Manage Compliance Cases') === true
                    && auth()->id() !== $record->classified_by)
                    ->schema([
                        Select::make('decision')->options(ComplianceCaseDispositionDecision::class)->required(),
                        Textarea::make('summary')->required()->maxLength(30000),
                    ])->action(fn (ComplianceCaseRetentionClassification $record, array $data) => app(ComplianceCaseRetentionManager::class)->review(auth()->user(), $record, $data)),
            ])->defaultSort('version');
    }
}
