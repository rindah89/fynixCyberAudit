<?php

namespace App\Filament\Resources\PrivacyProcessingActivityResource\RelationManagers;

use App\Enums\PrivacyAssessmentDecision;
use App\Models\PrivacyImpactAssessment;
use App\Privacy\PrivacyManagementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'assessments';

    protected static ?string $title = 'Independent privacy impact assessments';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['assessor:id,name', 'activityVersion:id,version,fingerprint']))->columns([TextColumn::make('version')->sortable(), TextColumn::make('decision')->badge(), TextColumn::make('residual_risk')->badge(), TextColumn::make('assessor.name')->label('Assessor'), TextColumn::make('assessed_at')->dateTime(), TextColumn::make('next_review_at')->date(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([
            Action::make('assess')->visible(fn (): bool => auth()->user()?->can('assess', $this->getOwnerRecord()) === true)->schema([Textarea::make('necessity_assessment')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('proportionality_assessment')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('risk_summary')->required()->maxLength(30000)->columnSpanFull(), TagsInput::make('mitigations')->required(), Select::make('residual_risk')->options(array_combine(['Low', 'Medium', 'High', 'Critical'], ['Low', 'Medium', 'High', 'Critical']))->required(), Select::make('decision')->options(PrivacyAssessmentDecision::class)->required(), Textarea::make('decision_summary')->required()->maxLength(30000)->columnSpanFull(), DatePicker::make('next_review_at')->required()->minDate(today())])->action(fn (array $data) => app(PrivacyManagementManager::class)->assess(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (PrivacyImpactAssessment $record) => view('filament.privacy-evidence', ['title' => 'DPIA '.$record->version, 'snapshot' => $record->toArray(), 'summary' => $record->decision_summary, 'fingerprint' => $record->fingerprint]))])->defaultSort('version', 'desc');
    }
}
