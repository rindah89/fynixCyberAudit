<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ComplianceCaseIntakeResource\Pages\ListComplianceCaseIntakes;
use App\Filament\Resources\ComplianceCaseIntakeResource\Pages\ViewComplianceCaseIntake;
use App\Filament\Resources\ComplianceCaseIntakeResource\RelationManagers\MessagesRelationManager;
use App\Models\ComplianceCaseIntake;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplianceCaseIntakeResource extends Resource
{
    protected static ?string $model = ComplianceCaseIntake::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-inbox-arrow-down';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Compliance intakes';

    protected static ?int $navigationSort = 44;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('compliance_cases') && self::canAccess();
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('compliance_cases') && auth()->user()?->can('Manage Compliance Cases') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['reporter:id,name,email', 'decision.actor:id,name,email', 'decision.complianceCase:id,number']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('reference')->searchable()->sortable(), TextColumn::make('title')->searchable(),
            TextColumn::make('category')->badge()->sortable(), TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('reporter.name')->label('Reporter')->searchable(),
            TextColumn::make('decision.decision')->label('Disposition')->badge()->placeholder('Pending')->sortable(),
            TextColumn::make('submitted_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Immutable authenticated intake')->columns(3)->schema([
                TextEntry::make('reference'), TextEntry::make('title')->columnSpan(2),
                TextEntry::make('category')->badge(), TextEntry::make('priority')->badge(), TextEntry::make('confidential')->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
                TextEntry::make('reporter.name')->label('Reporter'), TextEntry::make('reporter.email')->label('Reporter email'), TextEntry::make('submitted_at')->dateTime(),
                TextEntry::make('source_channel'), TextEntry::make('source_reference')->placeholder('Not supplied')->columnSpan(2),
                TextEntry::make('allegation')->columnSpanFull(), TextEntry::make('reporter_message')->placeholder('Not supplied')->columnSpanFull(),
                TextEntry::make('reporter_snapshot')->getStateUsing(fn (ComplianceCaseIntake $record): string => json_encode($record->reporter_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                TextEntry::make('fingerprint')->copyable()->columnSpanFull(),
            ]),
            Section::make('Terminal disposition')->columns(3)->schema([
                TextEntry::make('decision.decision')->badge()->placeholder('Pending'), TextEntry::make('decision.actor.name')->label('Decided by')->placeholder('Pending'),
                TextEntry::make('decision.decided_at')->dateTime()->placeholder('Pending'),
                TextEntry::make('decision.summary')->placeholder('Pending')->columnSpanFull(),
                TextEntry::make('decision.complianceCase.number')->label('Governed case')->placeholder('No case created'),
                TextEntry::make('decision.fingerprint')->copyable()->placeholder('Pending')->columnSpan(2),
                TextEntry::make('decision.actor_snapshot')->getStateUsing(fn (ComplianceCaseIntake $record): string => $record->decision === null ? __('Pending') : json_encode($record->decision->actor_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                TextEntry::make('decision.intake_snapshot')->getStateUsing(fn (ComplianceCaseIntake $record): string => $record->decision === null ? __('Pending') : json_encode($record->decision->intake_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                TextEntry::make('decision.case_snapshot')->getStateUsing(fn (ComplianceCaseIntake $record): string => $record->decision?->case_snapshot === null ? __('No case created') : json_encode($record->decision->case_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceCaseIntakes::route('/'), 'view' => ViewComplianceCaseIntake::route('/{record}')];
    }

    public static function getRelations(): array
    {
        return [MessagesRelationManager::class];
    }
}
