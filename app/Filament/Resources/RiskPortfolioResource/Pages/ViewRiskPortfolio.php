<?php

namespace App\Filament\Resources\RiskPortfolioResource\Pages;

use App\Enums\RiskGovernanceDecision;
use App\Filament\Resources\RiskPortfolioResource;
use App\Models\FileAttachment;
use App\Services\RiskPortfolioManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewRiskPortfolio extends ViewRecord
{
    protected static string $resource = RiskPortfolioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('review')->label('Record governance review')->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                ->schema([
                    Select::make('decision')->options(RiskGovernanceDecision::class)->required(),
                    Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(),
                    DatePicker::make('next_review_at')->required()->minDate(today()->addDay()),
                    TextInput::make('evidence_reference')->maxLength(255),
                    Select::make('evidence_attachment_ids')->label('Governed review evidence')
                        ->multiple()->maxItems(20)->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => $this->evidenceOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => $this->evidenceLabels($values))
                        ->helperText('Optional accepted audit-evidence files you are authorized to access. Fynix retains content and SHA-256 snapshots.'),
                ])->action(function (array $data): void {
                    app(RiskPortfolioManager::class)->review(
                        $this->record, auth()->user(), RiskGovernanceDecision::from($data['decision']), $data,
                    );
                    $this->record->refresh();
                    Notification::make()->title('Governance review recorded')->success()->send();
                }),
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
