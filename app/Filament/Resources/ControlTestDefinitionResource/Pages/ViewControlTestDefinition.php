<?php

namespace App\Filament\Resources\ControlTestDefinitionResource\Pages;

use App\ContinuousControlTesting\ControlTestRunner;
use App\Filament\Resources\ControlTestDefinitionResource;
use App\Models\FileAttachment;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewControlTestDefinition extends ViewRecord
{
    protected static string $resource = ControlTestDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('execute')->label('Record observation')->icon('heroicon-o-play')
                ->authorize(fn () => auth()->user()->can('execute', $this->record))
                ->schema([
                    TextInput::make('observed_value')->required()->maxLength(255),
                    Textarea::make('notes')->maxLength(10000),
                    TextInput::make('evidence_reference')->maxLength(255)
                        ->helperText('Operator-supplied external reference; Fynix does not verify the referenced evidence.'),
                    Select::make('evidence_attachment_ids')->label('Governed observation evidence')
                        ->multiple()->maxItems(20)->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                        ->helperText('Optional accepted audit-evidence files you are authorized to access. Fynix retains content and SHA-256 snapshots.'),
                ])
                ->action(fn (array $data) => app(ControlTestRunner::class)->execute(
                    $this->record, auth()->user(), $data['observed_value'], $data['notes'] ?? null, $data['evidence_reference'] ?? null,
                    $data['evidence_attachment_ids'] ?? [],
                )),
            EditAction::make(),
        ];
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
