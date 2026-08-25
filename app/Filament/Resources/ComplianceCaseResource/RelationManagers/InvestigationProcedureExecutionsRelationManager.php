<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseInvestigationProcedureExecutionManager;
use App\Enums\ComplianceCaseInvestigationProcedureResult;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseInvestigationPlan;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvestigationProcedureExecutionsRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationProcedureExecutions';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['executor:id,name,email', 'plan.review']))->columns([
            TextColumn::make('procedure_index')->label('#')->sortable(),
            TextColumn::make('procedure_text')->searchable()->wrap(), TextColumn::make('result')->badge(),
            TextColumn::make('executor.name')->searchable(), TextColumn::make('executed_at')->dateTime()->sortable(),
            TextColumn::make('fingerprint')->copyable()->toggleable(),
        ])->headerActions([
            Action::make('record_execution')->visible(fn (): bool => in_array($this->getOwnerRecord()->status, [ComplianceCaseStatus::Investigating, ComplianceCaseStatus::ActionRequired], true)
                && (auth()->user()->can('Manage Compliance Cases') || (auth()->user()->can('Investigate Compliance Cases') && $this->getOwnerRecord()->assigned_to === auth()->id())))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('procedure_index')->options(fn (): array => $this->procedureOptions())->required(),
                    Select::make('result')->options(ComplianceCaseInvestigationProcedureResult::class)->required(),
                    Textarea::make('summary')->required()->maxLength(30000),
                    Textarea::make('findings')->maxLength(30000), Textarea::make('source_reference')->maxLength(2000),
                ]))->action(fn (array $data) => app(ComplianceCaseInvestigationProcedureExecutionManager::class)->record(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))
                ->modalContent(fn ($record) => view('filament.compliance-case-investigation-procedure-execution', ['execution' => $record->fresh()->load(['executor', 'plan.review'])])),
        ])->defaultSort('procedure_index');
    }

    private function procedureOptions(): array
    {
        $plan = ComplianceCaseInvestigationPlan::query()->where('compliance_case_id', $this->getOwnerRecord()->id)->latest('version')->first();
        $completed = $this->getOwnerRecord()->investigationProcedureExecutions()->where('compliance_case_investigation_plan_id', $plan?->id)->pluck('procedure_index')->all();

        return collect($plan?->procedures ?? [])->mapWithKeys(fn (string $procedure, int $offset): array => [$offset + 1 => ($offset + 1).'. '.$procedure])
            ->except($completed)->all();
    }
}
