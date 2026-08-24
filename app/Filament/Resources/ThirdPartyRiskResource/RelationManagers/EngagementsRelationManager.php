<?php

namespace App\Filament\Resources\ThirdPartyRiskResource\RelationManagers;

use App\Enums\ThirdPartyContractDecision;
use App\Enums\ThirdPartyEngagementStatus;
use App\Models\ThirdPartyEngagement;
use App\Models\User;
use App\ThirdPartyRisk\ThirdPartyContractRiskManager;
use App\ThirdPartyRisk\ThirdPartyEngagementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
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
        return $table->modifyQueryUsing(fn ($query) => $query->with(['businessOwner:id,name', 'proposer:id,name', 'approver:id,name', 'events.actor:id,name', 'contractRiskReviews.reviewer:id,name'])->withCount('contractRiskReviews'))
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
                    ->modalContent(fn (ThirdPartyEngagement $record) => view('filament.third-party-engagement', ['engagement' => $record])),
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
}
