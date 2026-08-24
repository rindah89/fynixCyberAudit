<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Enums\AuditTimeEntryType;
use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditTimeEntryExporter;
use App\Models\AuditTimeEntry;
use App\Services\AuditEffortManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimeEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timeEntries';

    protected static ?string $title = 'Attributable audit time';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['procedure:id,code,title', 'user:id,name', 'entrant:id,name', 'reversal:id,reverses_time_entry_id']))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('entry_type')->badge(), TextColumn::make('work_date')->date()->sortable(),
                TextColumn::make('procedure.code')->placeholder('Audit level'), TextColumn::make('user.name'),
                TextColumn::make('minutes')->numeric(), TextColumn::make('activity')->limit(50), TextColumn::make('entered_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('record_time')->visible(fn (): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && ($this->getOwnerRecord()->manager_id === auth()->id() || $this->getOwnerRecord()->members()->whereKey(auth()->id())->exists()))
                    ->form([
                        Select::make('audit_procedure_id')->options($this->getOwnerRecord()->procedures()->where('assigned_to', auth()->id())->orderBy('code')->pluck('code', 'id'))->searchable(),
                        DatePicker::make('work_date')->required(), TextInput::make('minutes')->numeric()->minValue(1)->maxValue(1440)->required(),
                        TextInput::make('activity')->maxLength(255)->required(), Textarea::make('notes')->maxLength(10000),
                        TextInput::make('source_reference')->maxLength(2000)->helperText('Optional operator-supplied reference; Fynix does not verify it.'),
                    ])->action(fn (array $data) => app(AuditEffortManager::class)->record($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(AuditTimeEntryExporter::class),
            ])->recordActions([
                Action::make('reverse')->visible(fn (AuditTimeEntry $record): bool => $record->entry_type === AuditTimeEntryType::Work && ! $record->reversal
                    && ($record->user_id === auth()->id() || $this->getOwnerRecord()->manager_id === auth()->id() || auth()->user()?->can('Update Audits')))
                    ->form([TextInput::make('reason')->maxLength(240)->required(), Textarea::make('notes')->maxLength(10000)])
                    ->action(fn (AuditTimeEntry $record, array $data) => app(AuditEffortManager::class)->reverse($record, auth()->user(), $data)),
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AuditTimeEntry $record) => view('filament.audit-time-entry', ['entry' => $record])),
            ]);
    }
}
