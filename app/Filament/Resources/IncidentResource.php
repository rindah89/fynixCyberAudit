<?php

namespace App\Filament\Resources;

use App\Filament\Resources\IncidentResource\Pages\ListIncidents;
use App\Filament\Resources\IncidentResource\Pages\ViewIncident;
use App\Filament\Resources\IncidentResource\RelationManagers\AffectedEntitiesRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\LessonsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\NotificationsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\PhaseTransitionsRelationManager;
use App\Filament\Resources\IncidentResource\RelationManagers\TasksRelationManager;
use App\Models\Incident;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            && auth()->user()?->can('viewAny', Incident::class) === true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Governed incident')->columns(3)->schema([
                TextEntry::make('number'), TextEntry::make('title'), TextEntry::make('type')->placeholder('Not specified'),
                TextEntry::make('severity')->badge()->color(fn (string $state) => self::severityColor($state)),
                TextEntry::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextEntry::make('phase')->badge(),
                TextEntry::make('governance_status')->label('Governance')->badge()->color(fn (string $state) => $state === 'governed' ? 'success' : 'gray'),
                TextEntry::make('lead.name')->label('Lead'), TextEntry::make('reporter.name')->label('Reporter'),
                TextEntry::make('detected_at')->dateTime(),
                TextEntry::make('involves_data')->boolean(), TextEntry::make('involves_pii')->boolean(), TextEntry::make('is_breach')->boolean(),
                TextEntry::make('playbook_snapshot.name')->label('Captured playbook'),
                TextEntry::make('playbook_snapshot.description')->label('Captured playbook description')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')->searchable(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('phase')->badge(),
                TextColumn::make('severity')->badge()->color(fn (string $state) => self::severityColor($state)),
                TextColumn::make('status')->badge()->color(fn (string $state) => self::statusColor($state)),
                TextColumn::make('governance_status')->label('Governance')->badge()->color(fn (string $state) => $state === 'governed' ? 'success' : 'gray'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['lead:id,name', 'reporter:id,name']);
    }

    public static function getRelations(): array
    {
        return [
            TasksRelationManager::class, PhaseTransitionsRelationManager::class,
            NotificationsRelationManager::class, LessonsRelationManager::class, AffectedEntitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListIncidents::route('/'),
            'view' => ViewIncident::route('/{record}'),
        ];
    }

    private static function severityColor(string $severity): string
    {
        return match ($severity) {
            'Critical' => 'danger', 'High' => 'warning', 'Medium' => 'info', default => 'gray',
        };
    }

    private static function statusColor(string $status): string
    {
        return match ($status) {
            'Closed', 'Completed' => 'success', 'Open', 'In Progress' => 'warning', 'Cancelled' => 'gray', default => 'info',
        };
    }
}
