<?php

namespace App\Filament\Resources\GovernedModelResource\RelationManagers;

use App\Enums\ModelLifecycleStatus;
use App\Enums\ModelValidationDecision;
use App\ModelRisk\ModelRiskManager;
use App\Models\ModelValidationReview;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ValidationsRelationManager extends RelationManager
{
    protected static string $relationship = 'validationReviews';

    protected static ?string $title = 'Independent validation reviews';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('version')->sortable(), TextColumn::make('decision')->badge(), TextColumn::make('validator.name')->label('Validator'), TextColumn::make('validated_at')->dateTime(), TextColumn::make('valid_until')->date(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([Action::make('validate')->visible(fn (): bool => auth()->user()?->can('validateModel', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->lifecycle_status !== ModelLifecycleStatus::Retired)->schema([Textarea::make('scope')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('testing_performed')->required()->maxLength(30000)->columnSpanFull(), TagsInput::make('findings')->required(), Textarea::make('performance_summary')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('limitations_assessment')->required()->maxLength(30000)->columnSpanFull(), Select::make('decision')->options(ModelValidationDecision::class)->required(), TagsInput::make('conditions'), Textarea::make('decision_summary')->required()->maxLength(30000)->columnSpanFull(), DatePicker::make('valid_until')->required()->minDate(today()->addDay())])->action(fn (array $data) => app(ModelRiskManager::class)->validate(auth()->user(), $this->getOwnerRecord(), $data))])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (ModelValidationReview $record) => view('filament.model-risk-evidence', ['title' => 'Validation '.$record->version, 'snapshot' => $record->toArray(), 'summary' => $record->decision_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
