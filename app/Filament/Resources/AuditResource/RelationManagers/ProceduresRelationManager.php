<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Enums\AuditProcedureMethod;
use App\Enums\AuditProcedureOutcome;
use App\Enums\AuditWorkpaperReviewDecision;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditProcedureExporter;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditProcedure;
use App\Models\FileAttachment;
use App\Services\AuditProcedureManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProceduresRelationManager extends RelationManager
{
    protected static string $relationship = 'procedures';

    protected static ?string $title = 'Governed audit work program';

    private ?bool $closeoutFrozen = null;

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with([
            'auditItem.auditable', 'assignee:id,name', 'creator:id,name', 'execution.executor:id,name', 'execution.review.reviewer:id,name',
            'execution.evidence.attachment.audit.members', 'execution.evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('code')->searchable(), TextColumn::make('version')->sortable(), TextColumn::make('title')->searchable(),
                TextColumn::make('method')->badge(), TextColumn::make('assignee.name'), TextColumn::make('due_at')->date()->sortable(),
                TextColumn::make('status')->badge()->color(fn (string $state): string => $state === 'completed' ? 'success' : 'info'),
                TextColumn::make('execution.outcome')->badge()->placeholder('Pending'),
                TextColumn::make('execution.review.decision')->label('Supervisory review')->badge()->placeholder('Pending'),
            ])->headerActions([
                Action::make('define')->visible(fn (): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && ! $this->isCloseoutFrozen()
                    && (auth()->user()?->can('Update Audits') || $this->getOwnerRecord()->manager_id === auth()->id()))
                    ->form($this->definitionForm())->action(fn (array $data) => app(AuditProcedureManager::class)->define($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(AuditProcedureExporter::class),
            ])->recordActions([
                Action::make('execute')->visible(fn (AuditProcedure $record): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && ! $record->execution
                    && ! $this->isCloseoutFrozen()
                    && (auth()->user()?->can('Update Audits') || $this->getOwnerRecord()->manager_id === auth()->id() || $record->assigned_to === auth()->id()))
                    ->form($this->executionForm())->action(fn (AuditProcedure $record, array $data) => app(AuditProcedureManager::class)->execute($record, auth()->user(), $data)),
                Action::make('review_workpaper')->visible(fn (AuditProcedure $record): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && $record->execution && ! $record->execution->review
                    && $record->execution->executed_by !== auth()->id()
                    && ! $this->isCloseoutFrozen()
                    && (auth()->user()?->can('Update Audits') || $this->getOwnerRecord()->manager_id === auth()->id()))
                    ->form([
                        Select::make('decision')->options(AuditWorkpaperReviewDecision::class)->required(),
                        Textarea::make('review_summary')->maxLength(30000)->required(),
                    ])->action(fn (AuditProcedure $record, array $data) => app(AuditProcedureManager::class)->review($record->execution, auth()->user(), $data)),
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AuditProcedure $record) => view('filament.audit-procedure', ['procedure' => $record])),
            ]);
    }

    private function definitionForm(): array
    {
        $audit = $this->getOwnerRecord()->loadMissing('members');

        return [
            Select::make('audit_item_id')->options($audit->auditItems()->with('auditable')->get()->mapWithKeys(fn ($item) => [$item->id => '#'.$item->id.' · '.($item->auditable?->title ?? $item->auditable?->name ?? class_basename($item->auditable_type))]))->required()->searchable(),
            TextInput::make('code')->required()->maxLength(50), TextInput::make('title')->required()->maxLength(255),
            Textarea::make('objective')->required()->maxLength(10000), Textarea::make('steps')->required()->maxLength(30000),
            Select::make('method')->options(AuditProcedureMethod::class)->required(), Textarea::make('population_description')->maxLength(10000),
            TextInput::make('planned_sample_size')->numeric()->minValue(1)->maxValue(1000000),
            Select::make('assigned_to')->options($audit->members->push($audit->manager)->filter()->unique('id')->mapWithKeys(fn ($user) => [$user->id => $user->name]))->required()->searchable(),
            DatePicker::make('due_at'),
        ];
    }

    private function executionForm(): array
    {
        return [
            Select::make('outcome')->options(AuditProcedureOutcome::class)->required(), Textarea::make('result')->required()->maxLength(30000),
            Textarea::make('exceptions')->maxLength(30000), TextInput::make('sample_tested')->numeric()->minValue(0)->maxValue(1000000),
            Textarea::make('evidence_reference')->helperText('Optional operator-supplied reference; Fynix does not verify it.')->maxLength(2000),
            Select::make('evidence_attachment_ids')->label('Governed evidence attachments')->multiple()->maxItems(20)
                ->searchable()->options(fn (): array => $this->evidenceOptions())->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                ->helperText('Optional accepted audit files you can access. Fynix retains bounded copies and SHA-256 identity; it does not validate truth or sufficiency.'),
        ];
    }

    private function evidenceOptions(): array
    {
        $actor = auth()->user();

        return $actor ? FileAttachment::query()->eligibleGovernedEvidenceFor($actor)->latest('id')->limit(100)->get()
            ->mapWithKeys(fn (FileAttachment $file): array => [$file->id => $file->file_name.' · Audit '.$file->audit_id])->all() : [];
    }

    private function evidenceLabels(array $ids): array
    {
        $actor = auth()->user();

        return $actor ? FileAttachment::query()->eligibleGovernedEvidenceFor($actor)
            ->whereKey($ids)->pluck('file_name', 'id')->all() : [];
    }

    private function isCloseoutFrozen(): bool
    {
        return $this->closeoutFrozen ??= AuditCloseoutSubmission::freezesAudit($this->getOwnerRecord()->id);
    }
}
