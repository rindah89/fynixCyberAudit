<?php

namespace App\Filament\Resources\ThirdPartyRiskResource\RelationManagers;

use App\Enums\RiskIndicatorDirection;
use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyDueDiligenceDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyMonitoringCategory;
use App\Models\Survey;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\User;
use App\Models\VendorDocument;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use App\ThirdPartyRisk\ThirdPartyEngagementDueDiligenceManager;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EngagementsRelationManager extends RelationManager
{
    protected static string $relationship = 'engagements';

    protected static ?string $title = 'Third-party engagement lifecycle';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['businessOwner:id,name', 'proposer:id,name', 'approver:id,name', 'events.actor:id,name', 'contractRiskReviews.reviewer:id,name', 'dueDiligenceReviews.reviewer:id,name', 'monitoringIndicators.owner:id,name', 'monitoringIndicators.definer:id,name', 'monitoringIndicators.latestObservation.observer:id,name', 'monitoringIndicators.latestObservations.observer:id,name'])->withCount(['contractRiskReviews', 'dueDiligenceReviews']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state) => $state->getColor()),
                TextColumn::make('criticality')->badge(),
                IconColumn::make('data_access')->boolean(),
                TextColumn::make('businessOwner.name')->label('Business owner'),
                TextColumn::make('next_review_at')->date()->sortable(),
                TextColumn::make('contract_risk_reviews_count')->label('Contract reviews'),
                TextColumn::make('due_diligence_reviews_count')->label('Due-diligence reviews'),
            ])->headerActions([
                Action::make('propose')->label('Propose engagement')->icon('heroicon-o-plus')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Third Party Risk') ?? false)
                    ->schema([
                        TextInput::make('code')->required()->maxLength(100),
                        TextInput::make('name')->required()->maxLength(255),
                        Textarea::make('service_description')->required()->maxLength(30000)->columnSpanFull(),
                        Select::make('business_owner_id')->label('Business owner')->options(fn () => User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Select::make('criticality')->options(['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'])->required(),
                        Checkbox::make('data_access')->label('Processes or accesses organizational data'),
                        DatePicker::make('term_start_at')->required(),
                        DatePicker::make('term_end_at')->required(),
                        DatePicker::make('next_review_at')->required(),
                    ])->action(fn (array $data) => app(ThirdPartyEngagementManager::class)->propose(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading('Third-party engagement evidence')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (ThirdPartyEngagement $record) {
                        $manager = app(ThirdPartyEngagementDueDiligenceManager::class);
                        $visible = clone $record;
                        $visible->setRelation('dueDiligenceReviews', $manager->visibleReviews($record->dueDiligenceReviews, auth()->user()));

                        return view('filament.third-party-engagement', ['engagement' => $visible]);
                    }),
                Action::make('due_diligence_review')->label('Record due-diligence review')->icon('heroicon-o-magnifying-glass-circle')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && $record->status === ThirdPartyEngagementStatus::DueDiligence)
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('survey_id')->label('Completed vendor assessment')->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->surveyOptions($record, $search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->surveyOptions($record, '', [(int) $value])[(int) $value] ?? null)->required(),
                        Select::make('vendor_document_ids')->label('Approved supporting documents')->multiple()->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->documentOptions($record, $search))
                            ->getOptionLabelsUsing(fn (array $values): array => $this->documentOptions($record, '', array_map('intval', $values))),
                        TextInput::make('cybersecurity_rating')->numeric()->minValue(1)->maxValue(5)->required(),
                        TextInput::make('privacy_rating')->numeric()->minValue(1)->maxValue(5)->required(),
                        TextInput::make('resilience_rating')->numeric()->minValue(1)->maxValue(5)->required(),
                        TextInput::make('compliance_rating')->numeric()->minValue(1)->maxValue(5)->required(),
                        TextInput::make('financial_rating')->numeric()->minValue(1)->maxValue(5)->required(),
                        Textarea::make('findings_summary')->required()->maxLength(30000)->columnSpanFull(),
                        Select::make('decision')->options(ThirdPartyDueDiligenceDecision::class)->required(),
                        Textarea::make('conditions')->maxLength(30000)->columnSpanFull(),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                        DatePicker::make('next_review_at')->required(),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementDueDiligenceManager::class)->review(auth()->user(), $record, $data)),
                Action::make('contract_review')->label('Review contract risk')->icon('heroicon-o-document-check')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && in_array($record->status, [ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::RenewalReview], true))
                    ->schema([
                        TextInput::make('contract_reference')->required()->maxLength(255),
                        Select::make('agreement_type')->options(['master_service' => 'Master Service', 'statement_of_work' => 'Statement of Work', 'data_processing' => 'Data Processing', 'software_license' => 'Software License', 'other' => 'Other'])->required(),
                        DatePicker::make('effective_at')->required(), DatePicker::make('expires_at')->required(),
                        DatePicker::make('proposed_term_end_at')->visible(fn (ThirdPartyEngagement $record): bool => $record->status === ThirdPartyEngagementStatus::RenewalReview),
                        DatePicker::make('proposed_next_review_at')->visible(fn (ThirdPartyEngagement $record): bool => $record->status === ThirdPartyEngagementStatus::RenewalReview),
                        Checkbox::make('confidentiality_terms'), Checkbox::make('data_protection_terms'),
                        Checkbox::make('incident_notification_terms'), Checkbox::make('audit_rights'),
                        Checkbox::make('subcontractor_controls'), Checkbox::make('business_continuity_terms'),
                        Checkbox::make('termination_assistance'),
                        Textarea::make('service_level_summary')->required()->maxLength(30000),
                        Textarea::make('liability_summary')->required()->maxLength(30000),
                        Textarea::make('exit_terms_summary')->required()->maxLength(30000),
                        Textarea::make('exceptions_summary')->maxLength(30000),
                        Select::make('decision')->options(ThirdPartyContractDecision::class)->required(),
                        Textarea::make('conditions')->maxLength(30000),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyContractRiskManager::class)->review(auth()->user(), $record, $data)),
                Action::make('define_monitoring_indicator')->label('Define monitoring indicator')->icon('heroicon-o-chart-bar')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && $record->status === ThirdPartyEngagementStatus::Active)
                    ->schema([
                        TextInput::make('code')->required()->maxLength(100), TextInput::make('name')->required()->maxLength(255),
                        Textarea::make('description')->maxLength(30000),
                        Select::make('category')->options(ThirdPartyMonitoringCategory::class)->required(),
                        TextInput::make('unit')->required()->maxLength(50), Select::make('direction')->options(RiskIndicatorDirection::class)->required(),
                        TextInput::make('warning_threshold')->required(), TextInput::make('critical_threshold')->required(),
                        TextInput::make('frequency_days')->numeric()->minValue(1)->maxValue(366)->required(),
                        Select::make('owner_id')->options(fn () => User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        Textarea::make('measurement_method')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementMonitoringManager::class)->define(auth()->user(), $record, $data)),
                Action::make('record_monitoring_observation')->label('Record monitoring observation')->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (ThirdPartyEngagement $record): bool => $record->status === ThirdPartyEngagementStatus::Active && $record->monitoringIndicators->isNotEmpty() && ((auth()->user()?->can('Manage Third Party Risk') ?? false) || $record->business_owner_id === auth()->id() || $record->monitoringIndicators->contains('owner_id', auth()->id())))
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('indicator_id')->options($record->monitoringIndicators->sortByDesc('version')->unique('code')
                            ->filter(fn (ThirdPartyEngagementMonitoringIndicator $indicator): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) || $record->business_owner_id === auth()->id() || $indicator->owner_id === auth()->id())
                            ->mapWithKeys(fn (ThirdPartyEngagementMonitoringIndicator $indicator) => [$indicator->id => "{$indicator->code} v{$indicator->version} — {$indicator->name}"]))->required(),
                        TextInput::make('observed_value')->required(), DateTimePicker::make('observed_at')->seconds(false)->required(),
                        TextInput::make('source_reference')->maxLength(255), Textarea::make('notes')->maxLength(30000)->columnSpanFull(),
                    ])->action(function (array $data): void {
                        $indicator = ThirdPartyEngagementMonitoringIndicator::query()->findOrFail($data['indicator_id']);
                        unset($data['indicator_id']);
                        app(ThirdPartyEngagementMonitoringManager::class)->observe(auth()->user(), $indicator, $data);
                    }),
                Action::make('transition')->label('Record decision')->icon('heroicon-o-arrow-right')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && $record->status->allowedNext() !== [])
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('status')->options(collect($record->status->allowedNext())->mapWithKeys(fn ($status) => [$status->value => $status->getLabel()]))->required(),
                        Textarea::make('summary')->required()->maxLength(10000)->columnSpanFull(),
                        DatePicker::make('renewed_term_end_at')->visible(fn ($get) => $get('status') === ThirdPartyEngagementStatus::Active->value && $record->status === ThirdPartyEngagementStatus::RenewalReview),
                        DatePicker::make('renewed_next_review_at')->visible(fn ($get) => $get('status') === ThirdPartyEngagementStatus::Active->value && $record->status === ThirdPartyEngagementStatus::RenewalReview),
                        Textarea::make('exit_summary')->visible(fn ($get) => $get('status') === ThirdPartyEngagementStatus::Exited->value)->maxLength(30000),
                        Textarea::make('data_disposition_statement')->visible(fn ($get) => $get('status') === ThirdPartyEngagementStatus::Exited->value)->maxLength(30000),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementManager::class)->transition(auth()->user(), $record, $data)),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    /** @param  array<int, int>  $ids */
    private function surveyOptions(ThirdPartyEngagement $engagement, string $search, array $ids = []): array
    {
        return Survey::query()->where('vendor_id', $engagement->vendor_id)->where('type', 'vendor_assessment')->where('status', 'completed')
            ->whereNotNull('risk_score')->whereNotNull('risk_score_calculated_at')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [] && $search !== '', fn ($query) => $query->where('title', 'like', "%{$search}%"))
            ->orderBy('title')->limit(50)->get()->filter(fn (Survey $survey) => auth()->user()->can('view', $survey))->pluck('title', 'id')->all();
    }

    /** @param  array<int, int>  $ids */
    private function documentOptions(ThirdPartyEngagement $engagement, string $search, array $ids = []): array
    {
        return VendorDocument::query()->where('vendor_id', $engagement->vendor_id)->where('status', 'approved')
            ->where(fn ($query) => $query->whereNull('expiration_date')->orWhereDate('expiration_date', '>=', today()))
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [] && $search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(50)->get()->filter(fn (VendorDocument $document) => auth()->user()->can('view', $document))->pluck('name', 'id')->all();
    }
}
