<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Enums\RiskDomain;
use App\Enums\TechnologyExposureType;
use App\Filament\Exports\TechnologyExposureAssessmentExporter;
use App\Models\TechnologyExposureAssessment;
use App\Services\TechnologyExposureAssessmentManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class TechnologyExposureAssessmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'technologyExposureAssessments';

    protected static ?string $title = 'Technology threat and vulnerability assessments';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->domain === RiskDomain::Technology;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['asset:id,asset_tag,name', 'assessor:id,name']))->defaultSort('version', 'desc')->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('exposure_type')->badge()->color('gray'), TextColumn::make('title')->wrap()->searchable(),
            TextColumn::make('asset_snapshot.asset_tag')->label('Asset'), TextColumn::make('residual_score')->label('Residual')->badge()->color(fn (int $state) => $state >= 20 ? 'danger' : ($state >= 10 ? 'warning' : 'success')),
            TextColumn::make('state')->badge()->color(fn ($state) => ($state->value ?? $state) === 'above_appetite' ? 'danger' : 'success'),
            TextColumn::make('schedule_status')->label('Schedule')->badge()->color(fn (string $state) => $state === 'review_overdue' ? 'danger' : 'success'),
            TextColumn::make('review_due_at')->date()->sortable(), TextColumn::make('assessor.name')->label('Assessed by'), TextColumn::make('assessed_at')->dateTime()->sortable(),
        ])->headerActions([
            Action::make('assess')->label('Record technology exposure')->visible(fn () => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                ->schema([
                    Select::make('asset_id')->options(fn () => $this->getOwnerRecord()->assets()->where('is_active', true)->orderBy('name')->pluck('name', 'assets.id'))->searchable()->required(),
                    Select::make('exposure_type')->options(TechnologyExposureType::class)->required(), TextInput::make('title')->required()->maxLength(255),
                    Textarea::make('threat_scenario')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('vulnerability_reference')->maxLength(255),
                    Textarea::make('vulnerability_description')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('source_reference')->maxLength(255),
                    Select::make('inherent_likelihood')->options(array_combine(range(1, 5), range(1, 5)))->required(), Select::make('inherent_impact')->options(array_combine(range(1, 5), range(1, 5)))->required(),
                    Select::make('residual_likelihood')->options(array_combine(range(1, 5), range(1, 5)))->required(), Select::make('residual_impact')->options(array_combine(range(1, 5), range(1, 5)))->required(),
                    Textarea::make('recommended_response')->required()->maxLength(30000)->columnSpanFull(), DatePicker::make('review_due_at')->required()->minDate(today()),
                ])->action(fn (array $data) => app(TechnologyExposureAssessmentManager::class)->assess($this->getOwnerRecord(), auth()->user(), $data)),
            ExportAction::make()->exporter(TechnologyExposureAssessmentExporter::class)->visible(fn () => auth()->user()?->can('Manage Risk Portfolio') || auth()->user()?->can('Read Risks')),
        ])->recordActions([
            Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalHeading('Technology exposure assessment')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn (TechnologyExposureAssessment $record) => view('filament.technology-exposure-assessment', ['assessment' => $record])),
        ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
