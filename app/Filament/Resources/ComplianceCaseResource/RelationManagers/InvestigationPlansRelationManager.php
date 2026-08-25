<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseInvestigationPlanManager;
use App\Enums\ComplianceCaseInvestigationPlanDecision;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InvestigationPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'investigationPlans';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['author:id,name,email', 'review.reviewer:id,name,email']))->columns([
            TextColumn::make('version')->sortable(), TextColumn::make('author.name')->searchable(), TextColumn::make('target_completion_at')->date()->sortable(),
            TextColumn::make('review.decision')->badge()->placeholder('Pending'), TextColumn::make('review.reviewer.name')->placeholder('Pending'),
            TextColumn::make('review.fingerprint')->label('Review fingerprint')->copyable()->toggleable(),
            TextColumn::make('submitted_at')->dateTime()->sortable(), TextColumn::make('fingerprint')->copyable(),
        ])->headerActions([
            Action::make('submit_plan')->visible(fn (): bool => auth()->user()->can('Manage Compliance Cases') || (auth()->user()->can('Investigate Compliance Cases') && $this->getOwnerRecord()->assigned_to === auth()->id()))
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    TagsInput::make('objectives')->required(), Textarea::make('scope')->required()->maxLength(30000),
                    TagsInput::make('procedures')->required(), DatePicker::make('target_completion_at')->required()->minDate(today()),
                    Textarea::make('rationale')->required()->maxLength(30000),
                ]))->action(fn (array $data) => app(ComplianceCaseInvestigationPlanManager::class)->submit(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([
            Action::make('review')->visible(fn ($record): bool => auth()->user()->can('Manage Compliance Cases') && $record->review === null && $record->authored_by !== auth()->id() && $this->getOwnerRecord()->assigned_to !== auth()->id())
                ->schema(fn (Schema $schema): Schema => $schema->components([
                    Select::make('decision')->options(ComplianceCaseInvestigationPlanDecision::class)->required(),
                    Textarea::make('summary')->required()->maxLength(30000),
                ]))->action(fn ($record, array $data) => app(ComplianceCaseInvestigationPlanManager::class)->review(auth()->user(), $record, $data)),
            Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel(__('Close'))->modalContent(fn ($record) => view('filament.compliance-case-investigation-plan', ['plan' => $record->fresh()->load(['author', 'review.reviewer'])])),
        ])->defaultSort('version', 'desc');
    }
}
