<?php

namespace App\Filament\Resources\AiSystemResource\RelationManagers;

use App\AiGovernance\AiGovernanceManager;
use App\Enums\AiDecisionImpact;
use App\Enums\AiMonitoringOutcome;
use App\Models\AiUseCase;
use App\Models\FileAttachment;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class UseCasesRelationManager extends RelationManager
{
    protected static string $relationship = 'useCases';

    protected function getTableQuery(): Builder|Relation|null
    {
        return parent::getTableQuery()?->withGovernanceGraph()->withCount('monitoringReviews');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->wrap(), TextColumn::make('owner.name')->label('Owner'), TextColumn::make('decision_impact')->badge()->color(fn (AiDecisionImpact $state) => match ($state) {
                AiDecisionImpact::Low => 'success', AiDecisionImpact::Medium => 'warning', AiDecisionImpact::High, AiDecisionImpact::Critical => 'danger',
            }),
            IconColumn::make('uses_personal_data')->boolean(), IconColumn::make('automated_decision')->boolean(),
            TextColumn::make('governance_status')->badge()->color(fn (string $state) => match ($state) {
                'approved' => 'success', 'suspended', 'action_required', 'monitoring_overdue', 'approval_expired', 'rejected' => 'danger', default => 'warning',
            }), TextColumn::make('next_monitoring_at')->date()->placeholder('Not scheduled'),
            TextColumn::make('monitoring_reviews_count')->label('Reviews'),
        ])->recordActions([
            Action::make('monitor')->label('Record monitoring review')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => auth()->user()?->can('Manage AI Governance') ?? false)
                ->schema([
                    Select::make('outcome')->options(AiMonitoringOutcome::class)->required(),
                    Textarea::make('performance_summary')->required()->maxLength(30000)->columnSpanFull(),
                    TextInput::make('incidents_count')->numeric()->minValue(0)->maxValue(1000000)->default(0),
                    TextInput::make('complaints_count')->numeric()->minValue(0)->maxValue(1000000)->default(0),
                    DatePicker::make('next_review_at')->required()->minDate(today()->addDay()),
                    TextInput::make('evidence_reference')->maxLength(255),
                    Select::make('evidence_attachment_ids')->label('Governed monitoring evidence')
                        ->multiple()->maxItems(20)->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                        ->helperText('Optional accepted audit-evidence files you are authorized to access. Fynix retains content and SHA-256 snapshots.'),
                ])->action(fn (AiUseCase $record, array $data) => app(AiGovernanceManager::class)->monitor(
                    $record,
                    auth()->user(),
                    AiMonitoringOutcome::from($data['outcome']),
                    $data,
                )),
        ]);
    }

    private function evidenceOptions(string $search): array
    {
        return FileAttachment::query()->eligibleGovernedEvidenceFor(auth()->user())
            ->where('file_name', 'like', '%'.addcslashes($search, '%_').'%')
            ->orderByDesc('id')->limit(50)->pluck('file_name', 'id')->all();
    }

    private function evidenceLabels(array $values): array
    {
        return FileAttachment::query()->eligibleGovernedEvidenceFor(auth()->user())
            ->whereKey($values)->pluck('file_name', 'id')->all();
    }
}
