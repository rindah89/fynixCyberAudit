<?php

namespace App\Filament\Resources\PolicyResource\RelationManagers;

use App\Enums\PolicyExceptionDecisionType;
use App\Enums\PolicyExceptionMonitoringOutcome;
use App\Enums\PolicyExceptionStatus;
use App\Filament\Exports\PolicyExceptionExporter;
use App\Models\PolicyException;
use App\PolicyCompliance\PolicyExceptionGovernanceManager;
use App\PolicyCompliance\PolicyExceptionMonitoringManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ExceptionsRelationManager extends RelationManager
{
    protected static string $relationship = 'exceptions';

    protected static ?string $title = 'Policy exception history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->with([
            'requester' => fn ($query) => $query->withTrashed(), 'approver' => fn ($query) => $query->withTrashed(),
            'decisions.decider' => fn ($query) => $query->withTrashed(),
            'monitoringReviews.reviewer' => fn ($query) => $query->withTrashed(),
            'monitoringReviews.issue.lifecycle',
            'openMonitoringIssues' => fn ($issues) => $issues->select([
                'policy_exception_monitoring_issues.id',
                'policy_exception_monitoring_issues.policy_exception_monitoring_review_id',
                'policy_exception_monitoring_issues.status',
            ]),
        ]))->defaultSort('created_at', 'desc')->columns([
            TextColumn::make('name')->searchable()->sortable()->wrap()->limit(50),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('governance_fingerprint')->label('Evidence boundary')->badge()
                ->formatStateUsing(fn ($state): string => $state ? 'Governed' : 'Legacy')
                ->color(fn ($state): string => $state ? 'success' : 'gray'),
            TextColumn::make('requester.name')->label('Requested by'),
            TextColumn::make('effective_date')->date()->sortable(),
            TextColumn::make('expiration_date')->date()->sortable(),
            TextColumn::make('latest_monitoring_outcome')->label('Latest monitoring')->badge()->sortable(),
            TextColumn::make('monitoring_status')->label('Monitoring state')->badge(),
            TextColumn::make('next_review_at')->label('Next monitoring review')->dateTime()->sortable(),
            TextColumn::make('submitted_at')->dateTime()->sortable(),
        ])->filters([SelectFilter::make('status')->options(PolicyExceptionStatus::class)])
            ->headerActions([
                Action::make('submit')->label('Request exception')->icon('heroicon-o-shield-exclamation')
                    ->visible(fn (): bool => $this->getOwnerRecord()->owner_id === auth()->id()
                        || auth()->user()?->can('Read Policies') || auth()->user()?->can('Update Policies'))
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Textarea::make('description')->maxLength(30000),
                        Textarea::make('justification')->required()->maxLength(30000),
                        Textarea::make('risk_assessment')->required()->maxLength(30000),
                        Textarea::make('compensating_controls')->required()->maxLength(30000),
                        DatePicker::make('effective_date')->required()->minDate(today()),
                        DatePicker::make('expiration_date')->required()->after('effective_date'),
                        TextInput::make('review_frequency_days')->label('Review frequency (days)')->numeric()->integer()->minValue(1)->maxValue(365)->default(90)->required(),
                    ])->action(fn (array $data) => app(PolicyExceptionGovernanceManager::class)->submit($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(PolicyExceptionExporter::class)
                    ->visible(fn (): bool => (bool) auth()->user()?->can('Read Policies')),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading(fn (PolicyException $record): string => $record->name)
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (PolicyException $record) => view('filament.policy-exception-governance', ['exception' => $record])),
                Action::make('decide')->label('Decide')->icon('heroicon-o-check-badge')
                    ->visible(fn (PolicyException $record): bool => (bool) $record->governance_fingerprint
                        && auth()->user()?->can('Update Policies') && $record->requested_by !== auth()->id()
                        && in_array($record->status, [PolicyExceptionStatus::Pending, PolicyExceptionStatus::Approved], true))
                    ->schema(fn (PolicyException $record): array => [
                        Select::make('decision')->options($record->status === PolicyExceptionStatus::Pending
                            ? [PolicyExceptionDecisionType::Approved->value => __('Approved'), PolicyExceptionDecisionType::Denied->value => __('Denied')]
                            : [PolicyExceptionDecisionType::Revoked->value => __('Revoked')])->required(),
                        Textarea::make('decision_summary')->required()->maxLength(30000),
                    ])->action(fn (PolicyException $record, array $data) => app(PolicyExceptionGovernanceManager::class)->decide($record, auth()->user(), $data)),
                Action::make('monitor')->label('Record monitoring review')->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (PolicyException $record): bool => (bool) $record->governance_fingerprint
                        && $record->isActive() && auth()->user()?->can('Update Policies')
                        && ! in_array(auth()->id(), [$record->requested_by, $record->decisions->sortByDesc('version')->first()?->decided_by], true))
                    ->schema([
                        Select::make('outcome')->options(PolicyExceptionMonitoringOutcome::class)->required(),
                        Textarea::make('review_summary')->required()->maxLength(30000),
                        Textarea::make('control_effectiveness')->required()->maxLength(30000),
                        TextInput::make('evidence_reference')->maxLength(255),
                    ])->action(fn (PolicyException $record, array $data) => app(PolicyExceptionMonitoringManager::class)->review($record, auth()->user(), $data)),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
