<?php

namespace App\Filament\Resources;

use App\Enums\SystemAuthorizationDecision;
use App\Filament\Resources\SystemAuthorizationPackageResource\Pages\ListSystemAuthorizationPackages;
use App\Filament\Resources\SystemAuthorizationPackageResource\Pages\ViewSystemAuthorizationPackage;
use App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers\DecisionsRelationManager;
use App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers\MonitoringReviewsRelationManager;
use App\Models\SystemAuthorizationPackage;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SystemAuthorizationPackageResource extends Resource
{
    protected static ?string $model = SystemAuthorizationPackage::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'System authorization';

    protected static ?int $navigationSort = 48;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('system_authorization');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('system_authorization') && auth()->user()?->can('viewAny', SystemAuthorizationPackage::class) === true;
    }

    public static function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($q) => $q->with(['application:id,name', 'submitter:id,name', 'latestDecision', 'latestMonitoringReview']))->columns([TextColumn::make('application.name')->searchable(), TextColumn::make('version')->sortable(), TextColumn::make('impact_level')->badge(), TextColumn::make('authorization_state')->badge()->color(fn (string $s) => SystemAuthorizationDecision::tryFrom($s)?->getColor() ?? ($s === 'pending_review' ? 'info' : 'gray')), TextColumn::make('monitoring_state')->badge(), TextColumn::make('submitter.name')->label('Submitted by'), TextColumn::make('submitted_at')->dateTime()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Authorization package')->columns(3)->schema([TextEntry::make('application.name'), TextEntry::make('version'), TextEntry::make('authorization_state')->badge(), TextEntry::make('impact_level')->badge(), TextEntry::make('data_classifications')->listWithLineBreaks(), TextEntry::make('submitted_at')->dateTime(), TextEntry::make('system_boundary')->columnSpanFull(), TextEntry::make('monitoring_strategy')->columnSpanFull(), TextEntry::make('open_findings')->listWithLineBreaks()->columnSpanFull(), TextEntry::make('poam_reference')->placeholder('Not supplied'), TextEntry::make('fingerprint')->copyable()->columnSpan(2)])]);
    }

    public static function getRelations(): array
    {
        return [DecisionsRelationManager::class, MonitoringReviewsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListSystemAuthorizationPackages::route('/'), 'view' => ViewSystemAuthorizationPackage::route('/{record}')];
    }
}
