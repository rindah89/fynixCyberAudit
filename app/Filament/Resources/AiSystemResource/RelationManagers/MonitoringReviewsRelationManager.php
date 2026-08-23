<?php

namespace App\Filament\Resources\AiSystemResource\RelationManagers;

use App\Access\FileAccess;
use App\Filament\Exports\AiMonitoringReviewExporter;
use App\Models\AiMonitoringReview;
use App\Models\AiMonitoringReviewEvidence;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class MonitoringReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'monitoringReviews';

    protected static ?string $title = 'Append-only monitoring review history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reviewed_at')->dateTime()->sortable(),
            TextColumn::make('useCase.name')->label('Use case')->wrap()->searchable(),
            TextColumn::make('reviewer.name')->label('Reviewed by'),
            TextColumn::make('outcome')->badge()->color(fn ($state) => match ($state->value) {
                'satisfactory' => 'success', 'needs_action' => 'warning', 'suspended' => 'danger',
            }),
            TextColumn::make('performance_summary')->wrap()->limit(120),
            TextColumn::make('incidents_count')->label('Incidents')->numeric(),
            TextColumn::make('complaints_count')->label('Complaints')->numeric(),
            TextColumn::make('evidence_count')->label('Evidence')
                ->state(fn (AiMonitoringReview $record): int => $this->authorizedEvidence($record)->count()),
        ])->headerActions([
            ExportAction::make()->exporter(AiMonitoringReviewExporter::class),
        ])->recordActions([
            Action::make('inspect_evidence')->label('Evidence')->icon('heroicon-o-paper-clip')
                ->visible(fn (AiMonitoringReview $record): bool => $this->authorizedEvidence($record)->isNotEmpty())
                ->modalHeading('Governed AI monitoring evidence')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(function (AiMonitoringReview $record) {
                    $visibleRecord = clone $record;
                    $visibleRecord->setRelation('evidence', $this->authorizedEvidence($record));

                    return view('filament.ai-monitoring-review-evidence', [
                        'reviews' => collect([$visibleRecord]),
                        'actor' => auth()->user(),
                    ]);
                }),
        ])->modifyQueryUsing(fn ($query) => $query->with([
            'useCase:id,name', 'reviewer:id,name',
            'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]))
            ->defaultSort('reviewed_at', 'desc');
    }

    /** @return Collection<int, AiMonitoringReviewEvidence> */
    private function authorizedEvidence(AiMonitoringReview $record): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return collect();
        }

        return $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values();
    }
}
