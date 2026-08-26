<?php

namespace App\Filament\Resources;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\Filament\Resources\ComplianceCaseResource\Pages\ListComplianceCases;
use App\Filament\Resources\ComplianceCaseResource\Pages\ViewComplianceCase;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\AccessGrantsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ActionIssuesRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ArchiveManifestsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ClosureReportsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\CommunicationsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ConflictsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\EvidenceSubmissionsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InterviewsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationPlansRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationProcedureExecutionsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\InvestigationReportsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\LegalHoldsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\MilestonesRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\ReopenProposalsRelationManager;
use App\Filament\Resources\ComplianceCaseResource\RelationManagers\RetentionRelationManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ComplianceCaseResource extends Resource
{
    protected static ?string $model = ComplianceCase::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Compliance cases';

    protected static ?int $navigationSort = 45;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('compliance_cases');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return Enterprise::enabled('compliance_cases') && $user !== null
            && ($user->can('viewAny', ComplianceCase::class)
                || ComplianceCaseAccessGrantManager::granteeHasAnyActiveGrant($user));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Governed compliance case')->columns(3)->schema([
                TextEntry::make('number'), TextEntry::make('title')->columnSpan(2),
                TextEntry::make('category'), TextEntry::make('priority')->badge(), TextEntry::make('status')->badge(),
                TextEntry::make('investigation_planning_governance_status')->label('Investigation planning governance')->badge()->color(fn (string $state): string => $state === 'governed' ? 'success' : 'gray'),
                TextEntry::make('investigation_reporting_governance_status')->label('Investigation reporting governance')->badge()->color(fn (string $state): string => $state === 'governed' ? 'success' : 'gray'),
                TextEntry::make('opener.name')->label('Opened by'), TextEntry::make('assignee.name')->label('Investigator')->placeholder('Unassigned'),
                TextEntry::make('due_at')->dateTime()->placeholder('Not set'),
                TextEntry::make('allegation')->columnSpanFull(), TextEntry::make('source_channel')->placeholder('Not specified'),
                TextEntry::make('reporter_reference')->placeholder('Not specified'), TextEntry::make('source_reference')->columnSpanFull()->placeholder('Not specified'),
                TextEntry::make('triage_summary')->columnSpanFull()->placeholder('Not recorded'),
                TextEntry::make('investigation_summary')->columnSpanFull()->placeholder('Not recorded'),
                TextEntry::make('resolution_summary')->columnSpanFull()->placeholder('Not recorded'),
                TextEntry::make('closure_summary')->columnSpanFull()->placeholder('Not recorded'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->searchable()->sortable(), TextColumn::make('title')->searchable(),
            TextColumn::make('category')->sortable(), TextColumn::make('priority')->badge()->sortable(),
            TextColumn::make('status')->badge()->sortable(), TextColumn::make('assignee.name')->label('Investigator'),
            TextColumn::make('due_at')->dateTime()->sortable(),
        ])->defaultSort('id', 'desc');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['opener:id,name', 'assignee:id,name']);
        $actor = auth()->user();
        if ($actor) {
            ComplianceCaseAccessGrantManager::scopeVisibleTo($query, $actor);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class, InvestigationPlansRelationManager::class, InvestigationProcedureExecutionsRelationManager::class, InvestigationReportsRelationManager::class, ClosureReportsRelationManager::class, EvidenceSubmissionsRelationManager::class, InterviewsRelationManager::class, ActionIssuesRelationManager::class, LegalHoldsRelationManager::class, ConflictsRelationManager::class, MilestonesRelationManager::class, AccessGrantsRelationManager::class, CommunicationsRelationManager::class, RetentionRelationManager::class, ReopenProposalsRelationManager::class, ArchiveManifestsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListComplianceCases::route('/'), 'view' => ViewComplianceCase::route('/{record}')];
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', ComplianceCase::class) === true;
    }
}
