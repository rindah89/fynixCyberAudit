<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsgMaterialTopicResource\Pages\ListEsgMaterialTopics;
use App\Filament\Resources\EsgMaterialTopicResource\Pages\ViewEsgMaterialTopic;
use App\Filament\Resources\EsgMaterialTopicResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\EsgMaterialTopicResource\RelationManagers\VersionsRelationManager;
use App\Models\EsgMaterialTopic;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class EsgMaterialTopicResource extends Resource
{
    protected static ?string $model = EsgMaterialTopic::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-americas';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'ESG materiality';

    protected static ?int $navigationSort = 49;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('esg_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('esg_management') && auth()->user()?->can('viewAny', EsgMaterialTopic::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $q = parent::getEloquentQuery()->with(['owner:id,name', 'latestVersion', 'latestAssessment']);
        $u = auth()->user();
        if ($u && ! $u->can('Read ESG') && ! $u->can('Manage ESG') && ! $u->can('Assess ESG')) {
            $q->where('owner_id', $u->id);
        }

        return $q;
    }

    public static function table(Table $t): Table
    {
        return $t->columns([TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable(), TextColumn::make('pillar')->badge(), TextColumn::make('status')->badge(), TextColumn::make('owner.name')->label('Owner'), TextColumn::make('next_review_at')->date()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $s): Schema
    {
        return $s->components([Section::make('Material topic')->columns(3)->schema([TextEntry::make('code'), TextEntry::make('name')->columnSpan(2), TextEntry::make('pillar')->badge(), TextEntry::make('status')->badge(), TextEntry::make('owner.name'), TextEntry::make('description')->columnSpanFull(), TextEntry::make('impact_context')->columnSpanFull(), TextEntry::make('risk_context')->columnSpanFull(), TextEntry::make('opportunity_context')->columnSpanFull(), TextEntry::make('stakeholder_groups')->listWithLineBreaks(), TextEntry::make('framework_references')->listWithLineBreaks(), TextEntry::make('organizational_boundary')->columnSpanFull(), TextEntry::make('source_reference')->placeholder('Not supplied'), TextEntry::make('next_review_at')->date()])]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AssessmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListEsgMaterialTopics::route('/'), 'view' => ViewEsgMaterialTopic::route('/{record}')];
    }
}
