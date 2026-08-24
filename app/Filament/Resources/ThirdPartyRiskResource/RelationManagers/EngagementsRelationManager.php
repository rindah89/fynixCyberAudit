<?php

namespace App\Filament\Resources\ThirdPartyRiskResource\RelationManagers;

use App\Enums\RiskIndicatorDirection;
use App\Enums\ThirdPartyCollaborationCategory;
use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyDueDiligenceDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Enums\ThirdPartyMonitoringCategory;
use App\Enums\ThirdPartyOffboardingCategory;
use App\Enums\ThirdPartyOffboardingDecision;
use App\Enums\ThirdPartyOnboardingCategory;
use App\Enums\ThirdPartyOnboardingDecision;
use App\Models\Survey;
use App\Models\ThirdPartyEngagement;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\ThirdPartyEngagementMonitoringIndicator;
use App\Models\ThirdPartyEngagementOffboardingRequirement;
use App\Models\ThirdPartyEngagementOnboardingRequirement;
use App\Models\User;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use App\ThirdPartyRisk\ThirdPartyEngagementDueDiligenceManager;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use App\ThirdPartyRisk\ThirdPartyEngagementMonitoringManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOffboardingManager;
use App\ThirdPartyRisk\ThirdPartyEngagementOnboardingManager;
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
        return $table->modifyQueryUsing(fn ($query) => $query->with(['businessOwner:id,name', 'proposer:id,name', 'approver:id,name', 'events.actor:id,name', 'contractRiskReviews.reviewer:id,name', 'dueDiligenceReviews.reviewer:id,name', 'onboardingRequirements.owner:id,name', 'onboardingRequirements.definer:id,name', 'onboardingRequirements.completions.completer:id,name', 'onboardingReadinessReviews.reviewer:id,name', 'offboardingRequirements.owner:id,name', 'offboardingRequirements.definer:id,name', 'offboardingRequirements.completions.completer:id,name', 'offboardingReadinessReviews.reviewer:id,name', 'monitoringIndicators.owner:id,name', 'monitoringIndicators.definer:id,name', 'monitoringIndicators.latestObservation.observer:id,name', 'monitoringIndicators.latestObservations.observer:id,name', 'collaborationRequests.recipient:id,vendor_id,name,email', 'collaborationRequests.opener:id,name,email', 'collaborationRequests.events.evidence.document', 'collaborationRequests.latestEvent'])->withCount(['contractRiskReviews', 'dueDiligenceReviews', 'onboardingRequirements', 'onboardingReadinessReviews', 'offboardingRequirements', 'offboardingReadinessReviews', 'collaborationRequests']))
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
                TextColumn::make('onboarding_requirements_count')->label('Onboarding controls'),
                TextColumn::make('onboarding_readiness_reviews_count')->label('Readiness reviews'),
                TextColumn::make('offboarding_requirements_count')->label('Exit controls'),
                TextColumn::make('offboarding_readiness_reviews_count')->label('Exit reviews'),
                TextColumn::make('collaboration_requests_count')->label('Collaboration'),
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
                        $visible->setRelation('collaborationRequests', app(ThirdPartyEngagementCollaborationManager::class)->visibleRequests($record->collaborationRequests, auth()->user()));

                        return view('filament.third-party-engagement', ['engagement' => $visible]);
                    }),
                Action::make('open_collaboration')->label('Request provider response')->icon('heroicon-o-chat-bubble-left-right')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && in_array($record->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true))
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('category')->options(ThirdPartyCollaborationCategory::class)->required(),
                        TextInput::make('subject')->required()->maxLength(255),
                        Textarea::make('request_text')->required()->maxLength(30000)->columnSpanFull(),
                        Select::make('recipient_vendor_user_id')->label('Provider contact')->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => $this->vendorUserOptions($record, $search))
                            ->getOptionLabelUsing(fn ($value): ?string => $this->vendorUserOptions($record, '', [(int) $value])[(int) $value] ?? null)->required(),
                        DatePicker::make('due_at')->required(),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementCollaborationManager::class)->open(auth()->user(), $record, $data)),
                Action::make('decide_collaboration')->label('Review provider response')->icon('heroicon-o-shield-check')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false)
                        && in_array($record->status, [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                        && $record->collaborationRequests->contains(fn (ThirdPartyEngagementCollaborationRequest $request): bool => $request->opened_by !== auth()->id() && $request->latestStatus() === ThirdPartyCollaborationStatus::Responded))
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('collaboration_request_id')->label('Responded request')->options($record->collaborationRequests
                            ->filter(fn (ThirdPartyEngagementCollaborationRequest $request): bool => $request->opened_by !== auth()->id() && $request->latestStatus() === ThirdPartyCollaborationStatus::Responded)
                            ->mapWithKeys(fn (ThirdPartyEngagementCollaborationRequest $request) => [$request->id => "v{$request->version} — {$request->subject}"]))->required(),
                        Select::make('decision')->options([ThirdPartyCollaborationStatus::Accepted->value => ThirdPartyCollaborationStatus::Accepted->getLabel(), ThirdPartyCollaborationStatus::FollowUp->value => ThirdPartyCollaborationStatus::FollowUp->getLabel()])->required(),
                        Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(function (array $data): void {
                        $request = ThirdPartyEngagementCollaborationRequest::query()->findOrFail($data['collaboration_request_id']);
                        unset($data['collaboration_request_id']);
                        app(ThirdPartyEngagementCollaborationManager::class)->decide(auth()->user(), $request, $data);
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
                Action::make('define_onboarding_requirement')->label('Define onboarding control')->icon('heroicon-o-list-bullet')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && $record->status === ThirdPartyEngagementStatus::Approved)
                    ->schema([
                        Select::make('category')->options(ThirdPartyOnboardingCategory::class)->required(), TextInput::make('title')->required()->maxLength(255),
                        Textarea::make('acceptance_criteria')->required()->maxLength(30000)->columnSpanFull(),
                        Select::make('owner_id')->options(fn () => User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                        DatePicker::make('due_at')->required(), Checkbox::make('required')->default(true),
                    ])->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementOnboardingManager::class)->define(auth()->user(), $record, $data)),
                Action::make('complete_onboarding_requirement')->label('Complete onboarding control')->icon('heroicon-o-check-badge')
                    ->visible(fn (ThirdPartyEngagement $record): bool => $record->status === ThirdPartyEngagementStatus::Approved && $record->onboardingRequirements->contains(fn (ThirdPartyEngagementOnboardingRequirement $requirement) => (auth()->user()?->can('Manage Third Party Risk') ?? false) || $requirement->owner_id === auth()->id()))
                    ->schema(fn (ThirdPartyEngagement $record): array => [
                        Select::make('requirement_id')->options($record->onboardingRequirements->filter(fn (ThirdPartyEngagementOnboardingRequirement $requirement) => (auth()->user()?->can('Manage Third Party Risk') ?? false) || $requirement->owner_id === auth()->id())->mapWithKeys(fn (ThirdPartyEngagementOnboardingRequirement $requirement) => [$requirement->id => "v{$requirement->version} — {$requirement->title}"]))->required(),
                        Textarea::make('completion_summary')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('source_reference')->maxLength(255),
                    ])->action(function (array $data): void {
                        $requirement = ThirdPartyEngagementOnboardingRequirement::query()->findOrFail($data['requirement_id']);
                        unset($data['requirement_id']);
                        app(ThirdPartyEngagementOnboardingManager::class)->complete(auth()->user(), $requirement, $data);
                    }),
                Action::make('review_onboarding_readiness')->label('Review onboarding readiness')->icon('heroicon-o-shield-check')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && $record->status === ThirdPartyEngagementStatus::Approved && $record->onboardingRequirements->isNotEmpty())
                    ->schema([Select::make('decision')->options(ThirdPartyOnboardingDecision::class)->required(), Textarea::make('conditions')->maxLength(30000)->columnSpanFull(), Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(), DatePicker::make('next_review_at')->required()])
                    ->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementOnboardingManager::class)->review(auth()->user(), $record, $data)),
                Action::make('define_offboarding_requirement')->label('Define exit control')->icon('heroicon-o-list-bullet')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && in_array($record->status, [ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true))
                    ->schema([Select::make('category')->options(ThirdPartyOffboardingCategory::class)->required(), TextInput::make('title')->required()->maxLength(255), Textarea::make('acceptance_criteria')->required()->maxLength(30000)->columnSpanFull(), Select::make('owner_id')->options(fn () => User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(), DatePicker::make('due_at')->required(), Checkbox::make('required')->default(true)])
                    ->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementOffboardingManager::class)->define(auth()->user(), $record, $data)),
                Action::make('complete_offboarding_requirement')->label('Complete exit control')->icon('heroicon-o-check-badge')
                    ->visible(fn (ThirdPartyEngagement $record): bool => in_array($record->status, [ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true) && $record->offboardingRequirements->contains(fn (ThirdPartyEngagementOffboardingRequirement $requirement) => (auth()->user()?->can('Manage Third Party Risk') ?? false) || $requirement->owner_id === auth()->id()))
                    ->schema(fn (ThirdPartyEngagement $record): array => [Select::make('requirement_id')->options($record->offboardingRequirements->filter(fn (ThirdPartyEngagementOffboardingRequirement $requirement) => (auth()->user()?->can('Manage Third Party Risk') ?? false) || $requirement->owner_id === auth()->id())->mapWithKeys(fn (ThirdPartyEngagementOffboardingRequirement $requirement) => [$requirement->id => "v{$requirement->version} — {$requirement->title}"]))->required(), Textarea::make('completion_summary')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('source_reference')->maxLength(255)])
                    ->action(function (array $data): void {
                        $requirement = ThirdPartyEngagementOffboardingRequirement::query()->findOrFail($data['requirement_id']);
                        unset($data['requirement_id']);
                        app(ThirdPartyEngagementOffboardingManager::class)->complete(auth()->user(), $requirement, $data);
                    }),
                Action::make('review_offboarding_readiness')->label('Review exit readiness')->icon('heroicon-o-shield-check')
                    ->visible(fn (ThirdPartyEngagement $record): bool => (auth()->user()?->can('Manage Third Party Risk') ?? false) && in_array($record->status, [ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true) && $record->offboardingRequirements->isNotEmpty())
                    ->schema([Select::make('decision')->options(ThirdPartyOffboardingDecision::class)->required(), Textarea::make('conditions')->maxLength(30000)->columnSpanFull(), Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull()])
                    ->action(fn (ThirdPartyEngagement $record, array $data) => app(ThirdPartyEngagementOffboardingManager::class)->review(auth()->user(), $record, $data)),
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

    /** @param  array<int, int>  $ids */
    private function vendorUserOptions(ThirdPartyEngagement $engagement, string $search, array $ids = []): array
    {
        return VendorUser::query()->where('vendor_id', $engagement->vendor_id)->whereNull('deleted_at')->whereNotNull('password')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [] && $search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")))
            ->orderBy('name')->limit(50)->get()->mapWithKeys(fn (VendorUser $user) => [$user->id => "{$user->name} ({$user->email})"])->all();
    }
}
