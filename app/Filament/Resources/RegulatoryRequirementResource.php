<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RegulatoryRequirementResource\Pages\ListRegulatoryRequirements;
use App\Filament\Resources\RegulatoryRequirementResource\Pages\ViewRegulatoryRequirement;
use App\Filament\Resources\RegulatoryRequirementResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\RegulatoryRequirementResource\RelationManagers\VersionsRelationManager;
use App\Models\RegulatoryRequirement;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class RegulatoryRequirementResource extends Resource
{
    protected static ?string $model = RegulatoryRequirement::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Regulatory Requirements';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Policies') || $user->can('Update Policies')
            || RegulatoryRequirement::query()->where('owner_id', $user->id)
                ->orWhereHas('source', fn (Builder $query): Builder => $query->where('owner_id', $user->id))->exists());
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Read Policies') || $user->can('Update Policies')
            || $record->owner_id === $user->id || $record->source->owner_id === $user->id);
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
            TextColumn::make('source.code')->label('Source')->searchable()->sortable(),
            TextColumn::make('code')->searchable()->sortable(),
            TextColumn::make('latestVersion.title')->label('Requirement')->searchable(),
            TextColumn::make('latestVersion.version')->label('Version')->sortable(),
            TextColumn::make('governance_status')->label('Status')->badge()->color(fn (string $state): string => self::statusColor($state)),
            TextColumn::make('owner.name')->label('Owner'),
            TextColumn::make('latestVersion.effective_at')->label('Effective')->date()->sortable(),
        ])->defaultSort('updated_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Current regulatory requirement')->columns(3)->schema([
            TextEntry::make('source.code')->label('Source'), TextEntry::make('source.authority')->label('Authority'),
            TextEntry::make('source.jurisdiction')->label('Jurisdiction'), TextEntry::make('code'),
            TextEntry::make('latestVersion.version')->label('Current version'), TextEntry::make('governance_status')->label('Status'),
            TextEntry::make('latestVersion.title')->label('Title')->columnSpanFull(),
            TextEntry::make('latestVersion.requirement_text')->label('Requirement text')->columnSpanFull(),
            TextEntry::make('owner.name')->label('Owner'), TextEntry::make('latestVersion.publisher.name')->label('Published by'),
            TextEntry::make('latestVersion.published_at')->dateTime(),
            TextEntry::make('latestVersion.content_fingerprint')->label('Fingerprint')->copyable()->columnSpanFull(),
        ])]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with([
            'source:id,code,title,authority,jurisdiction,owner_id,status', 'owner:id,name',
            'latestVersion.publisher:id,name', 'latestVersion.latestAssessment.actionOwner:id,name',
        ]);
        $user = auth()->user();
        if ($user && ! $user->can('Read Policies') && ! $user->can('Update Policies')) {
            $query->where(fn (Builder $scope): Builder => $scope->where('owner_id', $user->id)
                ->orWhereHas('source', fn (Builder $source): Builder => $source->where('owner_id', $user->id)));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AssessmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListRegulatoryRequirements::route('/'), 'view' => ViewRegulatoryRequirement::route('/{record}')];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'applicable' => 'success',
            'under_review', 'assessment_required' => 'warning',
            'review_overdue', 'action_overdue', 'expired', 'repealed' => 'danger',
            'superseded' => 'warning',
            default => 'gray',
        };
    }
}
