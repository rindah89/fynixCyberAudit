<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsgDisclosureResource\Pages\ListEsgDisclosures;
use App\Filament\Resources\EsgDisclosureResource\Pages\ViewEsgDisclosure;
use App\Filament\Resources\EsgDisclosureResource\RelationManagers\DecisionsRelationManager;
use App\Models\EsgDisclosure;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EsgDisclosureResource extends Resource
{
    protected static ?string $model = EsgDisclosure::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'ESG disclosures';

    protected static ?int $navigationSort = 53;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('esg_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('esg_management') && auth()->user()?->can('viewAny', EsgDisclosure::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['preparer:id,name', 'latestDecision.decider:id,name']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('title')->searchable(), TextColumn::make('version'),
            TextColumn::make('reporting_period_start')->date(), TextColumn::make('reporting_period_end')->date(),
            TextColumn::make('preparer.name')->label('Prepared by'), TextColumn::make('latestDecision.decision')->label('Decision')->badge(),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed ESG disclosure version')->columns(3)->schema([
            TextEntry::make('code'), TextEntry::make('title')->columnSpan(2), TextEntry::make('reporting_period_start')->date(),
            TextEntry::make('reporting_period_end')->date(), TextEntry::make('preparer.name')->label('Prepared by'),
            TextEntry::make('framework_references')->listWithLineBreaks(), TextEntry::make('prepared_at')->dateTime(),
            TextEntry::make('fingerprint')->copyable(), TextEntry::make('narrative')->columnSpanFull(),
            TextEntry::make('validation_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
        ])]);
    }

    public static function getRelations(): array
    {
        return [DecisionsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEsgDisclosures::route('/'), 'view' => ViewEsgDisclosure::route('/{record}')];
    }
}
