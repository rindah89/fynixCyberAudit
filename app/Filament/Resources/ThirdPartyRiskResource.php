<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ThirdPartyRiskResource\Pages\ListThirdPartyRisks;
use App\Filament\Resources\ThirdPartyRiskResource\Pages\ViewThirdPartyRisk;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\EngagementsRelationManager;
use App\Filament\Resources\ThirdPartyRiskResource\RelationManagers\FourthPartyDependenciesRelationManager;
use App\Filament\Resources\VendorResource\RelationManagers\RiskAssessmentsRelationManager;
use App\Filament\Resources\VendorResource\RelationManagers\RiskDecisionsRelationManager;
use App\Filament\Resources\VendorResource\RelationManagers\RiskReviewsRelationManager;
use App\Filament\Resources\VendorResource\RelationManagers\RisksRelationManager;
use App\Models\Vendor;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ThirdPartyRiskResource extends Resource
{
    protected static ?string $model = Vendor::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Risk Management';

    protected static ?string $navigationLabel = 'Third-Party Risk';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->can('Manage Third Party Risk') || $user->can('Read Vendors') || Vendor::query()->where('vendor_manager_id', $user->id)->exists();
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->can('Manage Third Party Risk') || $user->can('Read Vendors') || $record->vendor_manager_id === $user->id;
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
        return $schema->components([Section::make('Third-party risk profile')->columns(3)->schema([
            TextEntry::make('name'), TextEntry::make('vendorManager.name')->label('Relationship manager'),
            TextEntry::make('third_party_risk_status')->label('Governance status')->badge()->color(fn (string $state) => self::statusColor($state)),
            TextEntry::make('risk_rating')->label('Organizational risk')->badge()->color(fn (Vendor $record) => $record->risk_rating->getColor()),
            TextEntry::make('risk_score')->label('Latest survey score')->formatStateUsing(fn (?int $state): string => $state === null ? 'Not scored' : "{$state}/100"),
            TextEntry::make('latestRiskAssessment.residual_score')->label('Latest residual score')->placeholder('Not assessed'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(), TextColumn::make('vendorManager.name')->label('Relationship manager'),
            TextColumn::make('risk_rating')->badge()->color(fn (Vendor $record) => $record->risk_rating->getColor()),
            TextColumn::make('latestRiskAssessment.residual_score')->label('Residual score')->badge()->placeholder('Not assessed'),
            TextColumn::make('third_party_risk_status')->label('Governance')->badge()->color(fn (string $state) => self::statusColor($state)),
            TextColumn::make('latestRiskDecision.next_review_at')->label('Next review')->date()->placeholder('Not scheduled'),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withThirdPartyRiskGraph()->with(['vendorManager' => fn ($query) => $query->withTrashed()]);
        $user = auth()->user();
        if ($user && ! $user->can('Manage Third Party Risk') && ! $user->can('Read Vendors')) {
            $query->where('vendor_manager_id', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [RiskAssessmentsRelationManager::class, RisksRelationManager::class, RiskDecisionsRelationManager::class, RiskReviewsRelationManager::class, EngagementsRelationManager::class, FourthPartyDependenciesRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListThirdPartyRisks::route('/'), 'view' => ViewThirdPartyRisk::route('/{record}')];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'approved' => 'success', 'conditionally_approved', 'assessment_required', 'risk_link_required', 'decision_required', 'reapproval_required' => 'warning',
            'rejected', 'terminated', 'termination_required', 'action_required', 'approval_expired', 'review_overdue' => 'danger', default => 'gray',
        };
    }
}
