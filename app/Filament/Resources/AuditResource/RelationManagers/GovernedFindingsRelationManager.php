<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Enums\AuditFindingFollowUpOutcome;
use App\Enums\AuditFindingSeverity;
use App\Enums\AuditManagementPosition;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditFindingExporter;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditFinding;
use App\Models\RemediationProject;
use App\Models\User;
use App\Services\AuditFindingManager;
use App\Services\AuditFindingRemediationManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class GovernedFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'governedFindings';

    protected static ?string $title = 'Governed findings and management responses';

    private ?bool $closeoutFrozen = null;

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['auditItem.auditable', 'accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name', 'latestResponse', 'remediation.task', 'remediation.followUps.reviewer:id,name', 'remediation.handoffActor:id,name']))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('code')->searchable(), TextColumn::make('title')->searchable(), TextColumn::make('severity')->badge(),
                TextColumn::make('accountableOwner.name')->label('Management owner'), TextColumn::make('latestResponse.position')->label('Position')->badge()->placeholder('Pending'),
                TextColumn::make('raised_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('raise_finding')->visible(fn (): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && ! $this->isCloseoutFrozen()
                    && (auth()->user()?->can('Update Audits') || $this->getOwnerRecord()->manager_id === auth()->id()))
                    ->form([
                        Select::make('audit_item_id')->options($this->getOwnerRecord()->auditItems()->with('auditable')->get()->mapWithKeys(fn ($item) => [$item->id => '#'.$item->id.' · '.($item->auditable?->title ?? $item->auditable?->name ?? class_basename($item->auditable_type))]))->required()->searchable(),
                        TextInput::make('title')->maxLength(255)->required(), Select::make('severity')->options(AuditFindingSeverity::class)->required(),
                        Textarea::make('condition')->maxLength(30000)->required(), Textarea::make('criteria')->maxLength(30000)->required(),
                        Textarea::make('cause')->maxLength(30000), Textarea::make('effect')->maxLength(30000)->required(),
                        Textarea::make('recommendation')->maxLength(30000)->required(),
                        Select::make('accountable_owner_id')->options(User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
                    ])->action(fn (array $data) => app(AuditFindingManager::class)->raise($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(AuditFindingExporter::class),
            ])->recordActions([
                Action::make('respond')->visible(fn (AuditFinding $record): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && ! $this->isCloseoutFrozen() && $record->accountable_owner_id === auth()->id())
                    ->form([
                        Select::make('position')->options(AuditManagementPosition::class)->required(), Textarea::make('response')->maxLength(30000)->required(),
                        Textarea::make('action_plan')->maxLength(30000), DatePicker::make('target_date'),
                    ])->action(fn (AuditFinding $record, array $data) => app(AuditFindingManager::class)->respond($record, auth()->user(), $data)),
                Action::make('handoff_remediation')->label('Hand off to remediation')
                    ->visible(fn (AuditFinding $record): bool => $record->remediation === null
                        && in_array($record->latestResponse?->position, [AuditManagementPosition::Agreed, AuditManagementPosition::PartiallyAgreed], true)
                        && (auth()->user()?->isSuperAdmin() || auth()->user()?->can('Manage Remediation'))
                        && ($this->getOwnerRecord()->manager_id === auth()->id() || auth()->user()?->can('Update Audits')))
                    ->form([
                        Select::make('remediation_project_id')->options(fn () => RemediationProject::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id'))->required()->searchable(),
                        Select::make('assignee_id')->options(User::query()->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id'))->searchable(),
                        TextInput::make('priority')->maxLength(50),
                    ])->action(function (AuditFinding $record, array $data): void {
                        $project = RemediationProject::query()->findOrFail($data['remediation_project_id']);
                        app(AuditFindingRemediationManager::class)->handoff($record, auth()->user(), $project, collect($data)->except('remediation_project_id')->all());
                    }),
                Action::make('follow_up')->label('Record effectiveness follow-up')
                    ->visible(fn (AuditFinding $record): bool => $this->canRecordFollowUp($record))
                    ->form([
                        Select::make('outcome')->options(AuditFindingFollowUpOutcome::class)->required(),
                        Textarea::make('summary')->maxLength(30000)->required(),
                        TextInput::make('evidence_reference')->maxLength(2000),
                    ])->action(fn (AuditFinding $record, array $data) => app(AuditFindingRemediationManager::class)->followUp($record->remediation, auth()->user(), $data)),
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AuditFinding $record) => view('filament.audit-finding', ['finding' => $record])),
            ]);
    }

    private function isCloseoutFrozen(): bool
    {
        return $this->closeoutFrozen ??= AuditCloseoutSubmission::freezesAudit($this->getOwnerRecord()->id);
    }

    private function canRecordFollowUp(AuditFinding $finding): bool
    {
        $actor = auth()->user();
        $remediation = $finding->remediation;
        if (! $actor?->can('Update Audits') || ! $remediation || ! in_array($remediation->task?->status, ['Completed', 'Closed', 'Done', 'Resolved'], true)) {
            return false;
        }
        if ($remediation->followUps->contains(fn ($followUp): bool => $followUp->outcome === AuditFindingFollowUpOutcome::Effective)) {
            return false;
        }
        $excluded = [$this->getOwnerRecord()->manager_id, $finding->accountable_owner_id, $remediation->task?->owner_id, $remediation->task?->assignee_id, $remediation->handed_off_by];

        return ! in_array($actor->id, array_filter($excluded), true)
            && ! $finding->responses->contains('responded_by', $actor->id);
    }
}
