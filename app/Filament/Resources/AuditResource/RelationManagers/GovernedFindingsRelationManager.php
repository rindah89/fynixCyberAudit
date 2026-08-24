<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Enums\AuditFindingSeverity;
use App\Enums\AuditManagementPosition;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditFindingExporter;
use App\Models\AuditCloseoutSubmission;
use App\Models\AuditFinding;
use App\Models\User;
use App\Services\AuditFindingManager;
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
        return $table->modifyQueryUsing(fn ($query) => $query->with(['auditItem.auditable', 'accountableOwner:id,name', 'raiser:id,name', 'responses.respondent:id,name', 'latestResponse']))
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
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AuditFinding $record) => view('filament.audit-finding', ['finding' => $record])),
            ]);
    }

    private function isCloseoutFrozen(): bool
    {
        return $this->closeoutFrozen ??= AuditCloseoutSubmission::freezesAudit($this->getOwnerRecord()->id);
    }
}
