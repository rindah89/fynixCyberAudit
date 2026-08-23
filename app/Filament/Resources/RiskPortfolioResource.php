<?php

namespace App\Filament\Resources;

use App\Enums\RiskDomain;
use App\Filament\Resources\RiskPortfolioResource\Pages\ListRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\Pages\ViewRiskPortfolio;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\GovernanceIssuesRelationManager;
use App\Filament\Resources\RiskPortfolioResource\RelationManagers\GovernanceReviewsRelationManager;
use App\Models\Risk;
use App\Models\RiskGovernanceProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RiskPortfolioResource extends Resource
{
    protected static ?string $model = Risk::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-pie';

    protected static string|\UnitEnum|null $navigationGroup = 'Risk Management';

    protected static ?string $navigationLabel = 'Risk Portfolio';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->can('Manage Risk Portfolio') || RiskGovernanceProfile::query()->where('owner_id', $user->id)->exists();
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->can('Manage Risk Portfolio') || $record->governanceProfile?->owner_id === $user->id;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Portfolio governance')->columns(3)->schema([
            TextEntry::make('code'), TextEntry::make('name'), TextEntry::make('domain')->badge()->color('gray'),
            TextEntry::make('governanceProfile.owner.name')->label('Accountable owner'),
            TextEntry::make('residual_risk')->label('Residual score')->badge()->color(fn (int $state) => $state >= 20 ? 'danger' : ($state >= 10 ? 'warning' : 'success')),
            TextEntry::make('governanceProfile.appetite_threshold')->label('Appetite threshold')->placeholder('Not profiled'),
            TextEntry::make('portfolio_governance_status')->label('Governance')->badge()->color(fn (string $state) => self::statusColor($state)),
            TextEntry::make('governanceProfile.strategic_objective')->label('Strategic objective')->placeholder('Not applicable'),
            TextEntry::make('governanceProfile.businessService.name')->label('Business service')->placeholder('Not applicable'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('residual_risk', 'desc')->columns([
            TextColumn::make('code')->searchable(), TextColumn::make('name')->searchable()->wrap(),
            TextColumn::make('domain')->badge()->color('gray'), TextColumn::make('governanceProfile.owner.name')->label('Owner'),
            TextColumn::make('residual_risk')->label('Residual')->badge()->color(fn (int $state) => $state >= 20 ? 'danger' : ($state >= 10 ? 'warning' : 'success'))->sortable(),
            TextColumn::make('governanceProfile.appetite_threshold')->label('Appetite')->placeholder('Not profiled'),
            TextColumn::make('portfolio_governance_status')->label('Governance')->badge()->color(fn (string $state) => self::statusColor($state)),
            TextColumn::make('latestGovernanceReview.next_review_at')->label('Next review')->date()->placeholder('Not scheduled'),
        ])->filters([SelectFilter::make('domain')->options([
            RiskDomain::Enterprise->value => RiskDomain::Enterprise->getLabel(),
            RiskDomain::Operational->value => RiskDomain::Operational->getLabel(),
            RiskDomain::Technology->value => RiskDomain::Technology->getLabel(),
        ])]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->whereIn('domain', [RiskDomain::Enterprise, RiskDomain::Operational, RiskDomain::Technology])->withPortfolioGovernanceGraph();
        $user = auth()->user();
        if ($user && ! $user->can('Manage Risk Portfolio')) {
            $query->whereHas('governanceProfile', fn (Builder $query) => $query->where('owner_id', $user->id));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [GovernanceReviewsRelationManager::class, GovernanceIssuesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListRiskPortfolio::route('/'), 'view' => ViewRiskPortfolio::route('/{record}')];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'accepted' => 'success', 'profile_required', 'review_required', 're_review_required' => 'warning',
            'mitigate', 'transfer', 'avoid', 'action_required', 'review_overdue' => 'danger', default => 'gray',
        };
    }
}
