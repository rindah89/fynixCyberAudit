<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PolicyAcknowledgementCampaignResource\Pages\ListPolicyAcknowledgementCampaigns;
use App\Filament\Resources\PolicyAcknowledgementCampaignResource\Pages\ViewPolicyAcknowledgementCampaign;
use App\Filament\Resources\PolicyAcknowledgementCampaignResource\RelationManagers\AssignmentsRelationManager;
use App\Models\Policy;
use App\Models\PolicyAcknowledgementCampaign;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PolicyAcknowledgementCampaignResource extends Resource
{
    protected static ?string $model = PolicyAcknowledgementCampaign::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-megaphone';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'Policy Campaigns';

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Update Policies')
            || Policy::query()->where('owner_id', $user->id)->exists()
            || PolicyAcknowledgementCampaign::query()->whereHas('policy', fn ($query) => $query->where('owner_id', $user->id))->exists());
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return $user && ($user->can('Update Policies') || $record->policy->owner_id === $user->id);
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
            TextColumn::make('policy.code')->label('Policy')->searchable(),
            TextColumn::make('version')->sortable(), TextColumn::make('title')->searchable(),
            TextColumn::make('campaign_status')->label('Status')->badge()->color(fn (string $state): string => self::statusColor($state)),
            TextColumn::make('acknowledged_count')->label('Acknowledged'),
            TextColumn::make('assignments_count')->label('Audience'),
            TextColumn::make('due_at')->dateTime()->sortable(), TextColumn::make('launcher.name')->label('Launched by'),
        ])->defaultSort('launched_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Campaign evidence')->columns(3)->schema([
            TextEntry::make('policy.code')->label('Policy'), TextEntry::make('version'), TextEntry::make('title'),
            TextEntry::make('campaign_status')->label('Status')->badge()->color(fn (string $state): string => self::statusColor($state)),
            TextEntry::make('due_at')->dateTime(), TextEntry::make('launched_at')->dateTime(),
            TextEntry::make('launcher.name')->label('Launched by'), TextEntry::make('closer.name')->label('Closed by')->placeholder('Open'),
            TextEntry::make('closed_at')->dateTime()->placeholder('Open'),
            TextEntry::make('policy_fingerprint')->columnSpanFull()->copyable(),
            TextEntry::make('instructions')->columnSpanFull()->placeholder('No additional instructions'),
        ])]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['policy:id,code,name,owner_id', 'launcher:id,name', 'closer:id,name'])
            ->withCount(['assignments', 'assignments as acknowledged_count' => fn ($query) => $query->has('acknowledgement')]);
        $user = auth()->user();
        if ($user && ! $user->can('Update Policies')) {
            $query->whereHas('policy', fn ($policy) => $policy->where('owner_id', $user->id));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [AssignmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPolicyAcknowledgementCampaigns::route('/'), 'view' => ViewPolicyAcknowledgementCampaign::route('/{record}')];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'complete' => 'success', 'active' => 'warning', 'overdue' => 'danger', default => 'gray',
        };
    }
}
