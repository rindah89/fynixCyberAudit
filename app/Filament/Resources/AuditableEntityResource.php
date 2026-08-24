<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AuditableEntityResource\Pages\ListAuditableEntities;
use App\Filament\Resources\AuditableEntityResource\Pages\ViewAuditableEntity;
use App\Filament\Resources\AuditableEntityResource\RelationManagers\AssessmentsRelationManager;
use App\Models\AuditableEntity;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class AuditableEntityResource extends Resource
{
    protected static ?string $model = AuditableEntity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static string|\UnitEnum|null $navigationGroup = 'Foundations';

    protected static ?string $navigationLabel = 'Audit Universe';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Programs') || $user->can('Update Programs') || AuditableEntity::query()->where('owner_id', $user->id)->exists());
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Programs') || $user->can('Update Programs') || $record->owner_id === $user->id);
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
            TextColumn::make('code')->searchable()->sortable(), TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('entity_type')->label('Type')->badge()->color('gray'), TextColumn::make('criticality')->badge(),
            TextColumn::make('planning_status')->label('Assessment state')->badge()->color(fn (string $state): string => match ($state) {
                'assessed' => 'success', 'assessment_required', 'reassessment_required' => 'warning', 'assessment_overdue' => 'danger', default => 'gray'
            }),
            TextColumn::make('latestAssessment.residual_score')->label('Residual'), TextColumn::make('latestAssessment.priority_band')->label('Priority')->badge()->color(fn (?string $state): string => match ($state) {
                'critical', 'high' => 'danger', 'medium' => 'warning', 'low' => 'success', default => 'gray'
            }),
            TextColumn::make('owner.name')->label('Owner'), TextColumn::make('next_assessment_at')->date()->sortable(),
        ])->defaultSort('next_assessment_at');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Auditable entity')->columns(3)->schema([
            TextEntry::make('code'), TextEntry::make('name'), TextEntry::make('entity_type')->label('Type')->badge()->color('gray'),
            TextEntry::make('criticality')->badge(), TextEntry::make('planning_status')->label('Assessment state'), TextEntry::make('owner.name')->label('Owner'),
            TextEntry::make('assessment_frequency')->label('Frequency'), TextEntry::make('next_assessment_at')->date(),
            TextEntry::make('risks_count')->label('Mapped risks'), TextEntry::make('controls_count')->label('Mapped controls'),
            TextEntry::make('description')->columnSpanFull()->placeholder('No description'),
        ])]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['owner:id,name', 'risks', 'controls', 'latestAssessment.assessor:id,name'])->withCount(['risks', 'controls']);
        $user = auth()->user();
        if ($user && ! $user->can('Read Programs') && ! $user->can('Update Programs')) {
            $query->where('owner_id', $user->id);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [AssessmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListAuditableEntities::route('/'), 'view' => ViewAuditableEntity::route('/{record}')];
    }
}
