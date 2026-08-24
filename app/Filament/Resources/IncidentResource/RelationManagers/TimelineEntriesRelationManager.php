<?php

namespace App\Filament\Resources\IncidentResource\RelationManagers;

use App\Enums\IncidentTimelineEntryType;
use App\Enums\IncidentTimelineVisibility;
use App\Incidents\IncidentTimelineManager;
use App\Models\IncidentTimelineEntry;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TimelineEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'timelineEntries';

    protected static ?string $title = 'Governed incident timeline';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(function ($query) {
            $query->with('recorder:id,name');
            if (auth()->user()?->cannot('update', $this->getOwnerRecord())) {
                $query->where('visibility', IncidentTimelineVisibility::Auditor->value);
            }
        })->columns([
            IconColumn::make('pinned')->boolean(),
            TextColumn::make('occurred_at')->dateTime()->sortable(),
            TextColumn::make('entry_type')->badge(), TextColumn::make('visibility')->badge(),
            TextColumn::make('summary')->wrap()->limit(120), TextColumn::make('recorder.name')->label('Recorded by'),
        ])->headerActions([
            Action::make('record')->label('Record timeline entry')->icon('heroicon-o-clock')
                ->visible(fn (): bool => auth()->user()?->can('update', $this->getOwnerRecord()) === true && $this->getOwnerRecord()->governed_at !== null)
                ->schema([
                    Select::make('entry_type')->options(IncidentTimelineEntryType::class)->required(),
                    Select::make('visibility')->options(IncidentTimelineVisibility::class)->required(),
                    DateTimePicker::make('occurred_at')->required()->maxDate(now()),
                    Toggle::make('pinned'), Textarea::make('summary')->required()->maxLength(10000)->columnSpanFull(),
                    Textarea::make('details')->maxLength(30000)->columnSpanFull(),
                ])->action(fn (array $data) => app(IncidentTimelineManager::class)->record(auth()->user(), $this->getOwnerRecord(), $data)),
        ])->recordActions([
            Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                ->modalContent(fn (IncidentTimelineEntry $record) => view('filament.incident-timeline-entry', ['record' => $record])),
        ])->defaultSort('occurred_at');
    }
}
