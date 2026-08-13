<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Models\Incident;
use App\Support\Enterprise;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-exclamation';

    protected static string|\UnitEnum|null $navigationGroup = 'Incidents';

    protected static ?string $navigationLabel = 'Incidents';

    protected static ?int $navigationSort = 30;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('incidents');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('incidents')
            && auth()->user()?->can('Manage Incidents');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('phase')->badge(),
                TextColumn::make('severity')->badge(),
                TextColumn::make('status')->badge(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
            'view' => ViewIncident::route('/{record}'),
        ];
    }
}
