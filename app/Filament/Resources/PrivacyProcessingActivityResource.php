<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PrivacyProcessingActivityResource\Pages\ListPrivacyProcessingActivities;
use App\Filament\Resources\PrivacyProcessingActivityResource\Pages\ViewPrivacyProcessingActivity;
use App\Filament\Resources\PrivacyProcessingActivityResource\RelationManagers\AssessmentsRelationManager;
use App\Filament\Resources\PrivacyProcessingActivityResource\RelationManagers\VersionsRelationManager;
use App\Models\PrivacyProcessingActivity;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrivacyProcessingActivityResource extends Resource
{
    protected static ?string $model = PrivacyProcessingActivity::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Privacy activities';

    protected static ?int $navigationSort = 46;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('privacy_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('privacy_management') && auth()->user()?->can('viewAny', PrivacyProcessingActivity::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', PrivacyProcessingActivity::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with('owner:id,name');
        $actor = auth()->user();
        if ($actor && ! $actor->can('Read Privacy') && ! $actor->can('Manage Privacy') && ! $actor->can('Assess Privacy')) {
            $query->where('owner_id', $actor->id);
        }

return $query;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('number')->searchable()->sortable(), TextColumn::make('name')->searchable(), TextColumn::make('status')->badge()->sortable(), TextColumn::make('owner.name')->label('Owner')->sortable(), TextColumn::make('lawful_basis')->label('Lawful basis'), TextColumn::make('next_review_at')->date()->sortable()])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed processing activity')->columns(3)->schema([
            TextEntry::make('number'), TextEntry::make('name')->columnSpan(2), TextEntry::make('status')->badge(), TextEntry::make('owner.name')->label('Owner'), TextEntry::make('next_review_at')->date(),
            TextEntry::make('purpose')->columnSpanFull(), TextEntry::make('lawful_basis'), TextEntry::make('retention_period'), TextEntry::make('special_category_data')->boolean(),
            TextEntry::make('data_subject_categories')->listWithLineBreaks(), TextEntry::make('personal_data_categories')->listWithLineBreaks(), TextEntry::make('recipient_categories')->listWithLineBreaks(),
            TextEntry::make('systems_and_vendors')->listWithLineBreaks(), TextEntry::make('processing_locations')->listWithLineBreaks(), TextEntry::make('cross_border_transfer')->boolean(),
            TextEntry::make('transfer_safeguards')->columnSpanFull()->placeholder('Not applicable'), TextEntry::make('security_measures')->columnSpanFull(), TextEntry::make('source_reference')->columnSpanFull()->placeholder('Not supplied'),
        ])]);
    }

    public static function getRelations(): array
    {
        return [VersionsRelationManager::class, AssessmentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPrivacyProcessingActivities::route('/'), 'view' => ViewPrivacyProcessingActivity::route('/{record}')];
    }
}
