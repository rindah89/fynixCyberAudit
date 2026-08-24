<?php

namespace App\Filament\Resources;

use App\Filament\Resources\EsgDataValidationResource\Pages\ListEsgDataValidations;
use App\Filament\Resources\EsgDataValidationResource\Pages\ViewEsgDataValidation;
use App\Models\EsgDataValidation;
use App\Models\EsgMaterialTopic;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class EsgDataValidationResource extends Resource
{
    protected static ?string $model = EsgDataValidation::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-check-badge';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'ESG data validation';

    protected static ?int $navigationSort = 52;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('esg_management');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('esg_management') && auth()->user()?->can('viewAny', EsgMaterialTopic::class) === true;
    }

    public static function canView(Model $record): bool
    {
        return $record instanceof EsgDataValidation && auth()->user()?->can('view', $record->observation->kpi->goal->topic) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['validator:id,name', 'observation.kpi.goal.topic:id,code,name,owner_id']);
        $actor = auth()->user();
        if ($actor && ! $actor->can('Read ESG') && ! $actor->can('Manage ESG') && ! $actor->can('Assess ESG') && ! $actor->can('Validate ESG Data') && ! $actor->can('Approve ESG Disclosures')) {
            $query->whereHas('observation.kpi', function (Builder $kpi) use ($actor): void {
                $kpi->where('owner_id', $actor->id)
                    ->orWhereHas('goal', fn (Builder $goal): Builder => $goal->where('owner_id', $actor->id))
                    ->orWhereHas('goal.topic', fn (Builder $topic): Builder => $topic->where('owner_id', $actor->id));
            });
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('observation.kpi.code')->label('KPI'), TextColumn::make('version'),
            TextColumn::make('outcome')->badge(), TextColumn::make('validator.name')->label('Validator'),
            TextColumn::make('validated_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable(),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Governed ESG data validation')->columns(2)->schema([
            TextEntry::make('outcome')->badge(), TextEntry::make('validator.name')->label('Validator'),
            TextEntry::make('completeness_assessment')->columnSpanFull(), TextEntry::make('accuracy_assessment')->columnSpanFull(),
            TextEntry::make('consistency_assessment')->columnSpanFull(), TextEntry::make('evidence_reference')->placeholder('Not supplied'),
            TextEntry::make('summary')->columnSpanFull(), TextEntry::make('validated_at')->dateTime(),
            TextEntry::make('fingerprint')->copyable(),
            TextEntry::make('observation_snapshot')->formatStateUsing(fn (array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
        ])]);
    }

    public static function getPages(): array
    {
        return ['index' => ListEsgDataValidations::route('/'), 'view' => ViewEsgDataValidation::route('/{record}')];
    }
}
