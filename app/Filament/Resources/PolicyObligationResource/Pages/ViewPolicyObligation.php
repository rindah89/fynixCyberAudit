<?php

namespace App\Filament\Resources\PolicyObligationResource\Pages;

use App\Enums\PolicyAttestationOutcome;
use App\Filament\Resources\PolicyObligationResource;
use App\Models\PolicyException;
use App\PolicyCompliance\PolicyCompliance;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPolicyObligation extends ViewRecord
{
    protected static string $resource = PolicyObligationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('attest')
                ->label('Submit attestation')
                ->icon('heroicon-o-check-badge')
                ->authorize(fn (): bool => auth()->user()->can('attest', $this->record))
                ->schema([
                    Select::make('outcome')->options(PolicyAttestationOutcome::class)->required(),
                    Textarea::make('statement')->required()->rows(4),
                    TextInput::make('evidence_reference')
                        ->label('Evidence reference')
                        ->helperText('Reference an evidence record, data request, audit item, or governed external repository.'),
                    Select::make('policy_exception_id')
                        ->label('Policy exception')
                        ->options(fn () => PolicyException::query()
                            ->where('policy_id', $this->record->policy_id)
                            ->active()
                            ->pluck('name', 'id'))
                        ->searchable(),
                ])
                ->action(function (array $data): void {
                    app(PolicyCompliance::class)->attest(
                        $this->record,
                        auth()->user(),
                        PolicyAttestationOutcome::from($data['outcome']),
                        $data['statement'],
                        $data['evidence_reference'] ?? null,
                        isset($data['policy_exception_id']) ? PolicyException::findOrFail($data['policy_exception_id']) : null,
                    );

                    $this->record->refresh();
                    Notification::make()->title('Attestation recorded')->success()->send();
                }),
            EditAction::make(),
        ];
    }
}
