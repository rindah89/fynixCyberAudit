<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditPlanResource\Pages\ListAuditPlans;
use App\Filament\Resources\AuditPlanResource\Pages\ViewAuditPlan;
use App\Filament\Resources\AuditPlanResource\RelationManagers\ItemsRelationManager;
use App\Models\AuditPlan;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditPlanResource extends Resource
{
    protected static ?string $model = AuditPlan::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static string|\UnitEnum|null $navigationGroup = 'Foundations';

    protected static ?string $navigationLabel = 'Risk-Based Audit Plans';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Programs') || $user->can('Update Programs') || AuditPlan::query()->where('manager_id', $user->id)->exists());
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Programs') || $user->can('Update Programs') || $record->manager_id === $user->id);
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

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('plan_year')->label('Year')->sortable(), TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('status')->badge(), TextColumn::make('items_count')->label('Planned entities'),
            TextColumn::make('manager.name')->label('Manager'), TextColumn::make('approver.name')->label('Approved by'),
            TextColumn::make('approved_at')->dateTime()->sortable(),
        ])->defaultSort('plan_year', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Risk-based audit plan evidence')->columns(3)->schema([
            TextEntry::make('plan_year')->label('Year'), TextEntry::make('name'), TextEntry::make('status')->badge(),
            TextEntry::make('manager.name')->label('Manager'), TextEntry::make('approver.name')->label('Approved by')->placeholder('Draft'),
            TextEntry::make('approved_at')->dateTime()->placeholder('Draft'), TextEntry::make('items_count')->label('Planned entities'),
            TextEntry::make('objective')->columnSpanFull(), TextEntry::make('approval_fingerprint')->copyable()->columnSpanFull()->placeholder('Draft'),
        ])]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['manager:id,name', 'approver:id,name'])->withCount('items');
        $user = auth()->user();
        if ($user && ! $user->can('Read Programs') && ! $user->can('Update Programs')) {
            $query->where('manager_id', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditPlans::route('/'), 'view' => ViewAuditPlan::route('/{record}')];
    }
}
