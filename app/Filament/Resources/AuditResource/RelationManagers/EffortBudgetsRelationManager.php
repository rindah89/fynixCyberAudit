<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Enums\WorkflowStatus;
use App\Filament\Exports\AuditEffortBudgetExporter;
use App\Models\AuditEffortBudget;
use App\Services\AuditEffortManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class EffortBudgetsRelationManager extends RelationManager
{
    protected static string $relationship = 'effortBudgets';

    protected static ?string $title = 'Governed effort budgets';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['procedure:id,code,title', 'user:id,name', 'setter:id,name']))
            ->defaultSort('id', 'desc')->columns([
                TextColumn::make('procedure.code')->placeholder('Audit level'), TextColumn::make('user.name'),
                TextColumn::make('version')->sortable(), TextColumn::make('planned_minutes')->label('Planned minutes')->numeric(),
                TextColumn::make('setter.name')->label('Set by'), TextColumn::make('set_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('set_budget')->visible(fn (): bool => $this->getOwnerRecord()->status === WorkflowStatus::INPROGRESS
                    && (auth()->user()?->can('Update Audits') || $this->getOwnerRecord()->manager_id === auth()->id()))
                    ->form([
                        Select::make('audit_procedure_id')->options($this->getOwnerRecord()->procedures()->orderBy('code')->pluck('code', 'id'))->searchable(),
                        Select::make('user_id')->options($this->teamOptions())->required()->searchable(),
                        TextInput::make('planned_minutes')->numeric()->minValue(1)->maxValue(600000)->required(),
                        Textarea::make('rationale')->maxLength(10000)->required(),
                    ])->action(fn (array $data) => app(AuditEffortManager::class)->budget($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(AuditEffortBudgetExporter::class),
            ])->recordActions([
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (AuditEffortBudget $record) => view('filament.audit-effort-budget', ['budget' => $record])),
            ]);
    }

    private function teamOptions(): array
    {
        $audit = $this->getOwnerRecord()->loadMissing('members', 'manager');

        return $audit->members->push($audit->manager)->filter()->unique('id')->mapWithKeys(fn ($user) => [$user->id => $user->name])->all();
    }
}
