<?php

namespace App\Filament\Resources\BusinessServiceResource\RelationManagers;

use App\Access\FileAccess;
use App\Models\FileAttachment;
use App\Models\RecoveryExercise;
use App\Models\RecoveryExerciseEvidence;
use App\Models\User;
use App\OperationalResilience\ResilienceManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class RecoveryExercisesRelationManager extends RelationManager
{
    protected static string $relationship = 'recoveryExercises';

    protected static ?string $title = 'Recovery exercise history';

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('scenario')->wrap()->limit(100),
            TextColumn::make('recoveryPlan.title')->label('Plan')->wrap(),
            TextColumn::make('scheduled_at')->dateTime()->sortable(),
            TextColumn::make('completed_at')->dateTime()->placeholder('Pending'),
            TextColumn::make('completer.name')->label('Completed by')->placeholder('Pending'),
            TextColumn::make('outcome')->badge()->placeholder('Pending')->color(fn ($state) => match ($state?->value) {
                'passed' => 'success', 'partial' => 'warning', 'failed' => 'danger', default => 'gray',
            }),
            TextColumn::make('evidence_count')->label('Governed evidence')
                ->state(fn (RecoveryExercise $record): int => $this->authorizedEvidence($record)->count()),
        ])->recordActions([
            Action::make('complete')->label('Complete exercise')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (RecoveryExercise $record): bool => $record->completed_at === null
                    && (auth()->user()?->can('Manage Resilience') ?? false))
                ->schema([
                    TextInput::make('actual_recovery_time_minutes')->numeric()->minValue(0)->maxValue(525600)->required(),
                    TextInput::make('actual_recovery_point_minutes')->numeric()->minValue(0)->maxValue(525600)->required(),
                    Textarea::make('observations')->required()->maxLength(30000)->columnSpanFull(),
                    TextInput::make('evidence_reference')->maxLength(255),
                    Select::make('evidence_attachment_ids')->label('Governed exercise evidence')
                        ->multiple()->maxItems(20)->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                        ->helperText('Optional accepted audit-evidence files you are authorized to access. Fynix retains content and SHA-256 snapshots.'),
                ])->action(fn (RecoveryExercise $record, array $data) => app(ResilienceManager::class)->completeExercise(
                    $record, auth()->user(), $data,
                )),
            Action::make('inspect_evidence')->label('Evidence')->icon('heroicon-o-paper-clip')
                ->visible(fn (RecoveryExercise $record): bool => $this->authorizedEvidence($record)->isNotEmpty())
                ->modalHeading('Governed recovery-exercise evidence')
                ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(function (RecoveryExercise $record) {
                    $visibleRecord = clone $record;
                    $visibleRecord->setRelation('evidence', $this->authorizedEvidence($record));

                    return view('filament.recovery-exercise-evidence', ['exercise' => $visibleRecord, 'actor' => auth()->user()]);
                }),
        ])->modifyQueryUsing(fn ($query) => $query->with([
            'recoveryPlan:id,title,business_service_id', 'completer:id,name',
            'evidence.attachment.audit.members',
            'evidence.attachment.dataRequestResponse.dataRequest.audit.members',
        ]))->defaultSort('scheduled_at', 'desc');
    }

    /** @return Collection<int, RecoveryExerciseEvidence> */
    private function authorizedEvidence(RecoveryExercise $record): Collection
    {
        $actor = auth()->user();
        if (! $actor instanceof User) {
            return collect();
        }

        return $record->evidence->filter(fn ($evidence): bool => $evidence->attachment !== null
            && app(FileAccess::class)->canDownloadFileAttachment($actor, $evidence->attachment))->values();
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
