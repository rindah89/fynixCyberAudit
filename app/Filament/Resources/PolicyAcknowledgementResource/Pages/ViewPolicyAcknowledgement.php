<?php

namespace App\Filament\Resources\PolicyAcknowledgementResource\Pages;

use App\Filament\Resources\PolicyAcknowledgementResource;
use App\PolicyCompliance\PolicyAcknowledgementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewPolicyAcknowledgement extends ViewRecord
{
    protected static string $resource = PolicyAcknowledgementResource::class;

    protected function getHeaderActions(): array
    {
        $record = $this->getRecord();
        $check = $record->campaign->knowledge_check;
        $passed = $record->knowledgeCheckAttempts->contains('passed', true);

        $actions = [];
        if ($check && ! $passed && $record->knowledgeCheckAttempts->count() < $check['max_attempts']
            && ! $record->acknowledgement && ! $record->campaign->closed_at) {
            $actions[] = Action::make('knowledge_check')->label('Complete comprehension check')->icon('heroicon-o-academic-cap')
                ->schema(collect($check['questions'])->map(fn (array $question): Radio => Radio::make('answers.'.$question['code'])
                    ->label($question['prompt'])->options($question['options'])->required())->all())
                ->action(fn (array $data) => app(PolicyAcknowledgementManager::class)
                    ->submitKnowledgeCheck($this->getRecord(), auth()->user(), $data['answers']));
        }
        $actions[] = Action::make('acknowledge')->label('Acknowledge policy')->icon('heroicon-o-check-circle')
            ->visible(fn (): bool => ! $this->getRecord()->acknowledgement && ! $this->getRecord()->campaign->closed_at
                && (! $this->getRecord()->campaign->knowledge_check || $this->getRecord()->knowledgeCheckAttempts->contains('passed', true)))
            ->schema([
                Checkbox::make('acknowledged')->label(PolicyAcknowledgementManager::STATEMENT)->accepted()->required(),
                Textarea::make('comment')->maxLength(2000),
                TextInput::make('client_reference')->maxLength(255),
            ])->action(fn (array $data) => app(PolicyAcknowledgementManager::class)->acknowledge($this->getRecord(), auth()->user(), $data));

        return $actions;
    }
}
