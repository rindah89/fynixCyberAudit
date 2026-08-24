<?php

namespace App\Filament\Resources\ThirdPartyRiskResource\RelationManagers;

use App\Enums\FourthPartyCriticality;
use App\Enums\FourthPartyDependencyCategory;
use App\Enums\FourthPartyDependencyStatus;
use App\Filament\Exports\VendorFourthPartyDependencyExporter;
use App\Models\BusinessService;
use App\Models\Vendor;
use App\Models\VendorFourthPartyDependency;
use App\Services\FourthPartyDependencyManager;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FourthPartyDependenciesRelationManager extends RelationManager
{
    protected static string $relationship = 'fourthPartyDependencies';

    protected static ?string $title = 'Fourth-party dependency history';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(['recorder:id,name', 'businessService:id,code,name', 'fourthPartyVendor:id,name']))
            ->defaultSort('recorded_at', 'desc')
            ->columns([
                TextColumn::make('fourth_party_name')->label('Fourth party')->searchable(),
                TextColumn::make('version')->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state) => $state->getColor()),
                TextColumn::make('criticality')->badge()->color(fn ($state) => $state->getColor()),
                TextColumn::make('category')->badge()->color('gray'),
                TextColumn::make('businessService.code')->label('Service')->placeholder('Not mapped'),
                IconColumn::make('data_access')->boolean(),
                TextColumn::make('recorder.name')->label('Recorded by'),
                TextColumn::make('recorded_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('record')->label('Record dependency')->icon('heroicon-o-link')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Third Party Risk') ?? false)
                    ->schema([
                        Select::make('fourth_party_vendor_id')->label('Known fourth-party vendor')
                            ->options(fn () => Vendor::query()->whereKeyNot($this->getOwnerRecord()->id)->orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                        TextInput::make('fourth_party_name')->label('External fourth-party name')->maxLength(255),
                        Select::make('business_service_id')->label('Affected business service')
                            ->options(fn () => BusinessService::query()->orderBy('name')->pluck('name', 'id'))->searchable(),
                        Select::make('status')->options(FourthPartyDependencyStatus::class)->required()->default(FourthPartyDependencyStatus::Active),
                        Select::make('category')->options(FourthPartyDependencyCategory::class)->required(),
                        Select::make('criticality')->options(FourthPartyCriticality::class)->required(),
                        Checkbox::make('data_access')->label('Processes or accesses organizational data'),
                        TextInput::make('source_reference')->maxLength(255),
                        Textarea::make('service_description')->required()->maxLength(2000)->columnSpanFull(),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (array $data) => app(FourthPartyDependencyManager::class)->record($this->getOwnerRecord(), auth()->user(), $data)),
                ExportAction::make()->exporter(VendorFourthPartyDependencyExporter::class)
                    ->visible(fn (): bool => auth()->user()?->can('Manage Third Party Risk') || auth()->user()?->can('Read Vendors')),
            ])->recordActions([
                Action::make('inspect')->label('Inspect')->icon('heroicon-o-eye')
                    ->modalHeading('Fourth-party dependency snapshot')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (VendorFourthPartyDependency $record) => view('filament.fourth-party-dependency', [
                        'dependency' => $record,
                        'concentration' => app(FourthPartyDependencyManager::class)->vendorConcentration($record),
                    ])),
            ]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
