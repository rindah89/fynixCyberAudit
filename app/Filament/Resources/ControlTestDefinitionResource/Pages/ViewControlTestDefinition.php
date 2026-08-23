<?php

namespace App\Filament\Resources\ControlTestDefinitionResource\Pages;

use App\ContinuousControlTesting\ControlTestRunner;
use App\Filament\Resources\ControlTestDefinitionResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
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
                ])
                ->action(fn (array $data) => app(ControlTestRunner::class)->execute(
                    $this->record, auth()->user(), $data['observed_value'], $data['notes'] ?? null, $data['evidence_reference'] ?? null,
                )),
            EditAction::make(),
        ];
    }
}
