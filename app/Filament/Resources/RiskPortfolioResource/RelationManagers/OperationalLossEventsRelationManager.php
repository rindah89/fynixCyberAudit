<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Enums\OperationalLossEventCategory;
use App\Enums\RiskDomain;
use App\Filament\Exports\OperationalLossEventExporter;
use App\Models\OperationalLossEvent;
use App\Services\OperationalLossEventManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OperationalLossEventsRelationManager extends RelationManager
{
    protected static string $relationship = 'operationalLossEvents';

    protected static ?string $title = 'Operational loss-event history';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->domain === RiskDomain::Operational;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['reporter:id,name', 'businessService:id,code,name']))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')->date()->sortable(),
                TextColumn::make('category')->badge()->color('gray'),
                TextColumn::make('business_service_snapshot.code')->label('Service'),
                TextColumn::make('gross_loss')->label('Gross')->money(fn ($record) => $record->currency),
                TextColumn::make('recoveries')->money(fn ($record) => $record->currency),
                TextColumn::make('net_loss')->label('Net')->money(fn ($record) => $record->currency),
                TextColumn::make('reporter.name')->label('Reported by'),
                TextColumn::make('recorded_at')->dateTime(),
            ])->headerActions([
                Action::make('record')->label('Record loss event')->icon('heroicon-o-document-plus')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                    ->schema([
                        Select::make('category')->options(OperationalLossEventCategory::class)->required(),
                        DatePicker::make('occurred_at')->required()->maxDate(today()),
                        DatePicker::make('detected_at')->required()->maxDate(today()),
                        TextInput::make('gross_loss')->numeric()->required()->maxLength(17),
                        TextInput::make('recoveries')->numeric()->default('0.00')->maxLength(17),
                        TextInput::make('currency')->required()->length(3)->default('USD'),
                        TextInput::make('source_reference')->maxLength(255),
                        Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(OperationalLossEventManager::class)->record(
                        $this->getOwnerRecord(), auth()->user(), $data,
                    )),
                ExportAction::make()->exporter(OperationalLossEventExporter::class)
                    ->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') || auth()->user()?->can('Read Risks')),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading('Operational loss event')
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (OperationalLossEvent $record) => view('filament.operational-loss-event', ['event' => $record])),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
