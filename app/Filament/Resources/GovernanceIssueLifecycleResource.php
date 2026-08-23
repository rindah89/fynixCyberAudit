<?php

namespace App\Filament\Resources;

use App\Enums\GovernanceIssueStatus;
use App\Filament\Exports\GovernanceIssueLifecycleExporter;
use App\Filament\Resources\GovernanceIssueLifecycleResource\Pages\ListGovernanceIssueLifecycles;
use App\Filament\Resources\GovernanceIssueLifecycleResource\Pages\ViewGovernanceIssueLifecycle;
use App\Filament\Resources\GovernanceIssueLifecycleResource\RelationManagers\ClosureEvidenceRelationManager;
use App\Filament\Resources\GovernanceIssueLifecycleResource\RelationManagers\TransitionsRelationManager;
use App\Models\FileAttachment;
use App\Models\GovernanceIssueLifecycle;
use App\Models\RemediationProject;
use App\Models\User;
use App\Services\GovernanceIssueLifecycleManager;
use App\Support\Enterprise;
use Filament\Actions\Action;
use Filament\Actions\ExportAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class GovernanceIssueLifecycleResource extends Resource
{
    protected static ?string $model = GovernanceIssueLifecycle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-path-rounded-square';

    protected static string|\UnitEnum|null $navigationGroup = 'Remediation';

    protected static ?string $navigationLabel = 'Governance Issues';

    protected static ?string $recordTitleAttribute = 'id';

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('remediation') && static::canViewAny();
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        return $user->can('Manage Issue Lifecycle') || $user->can('Verify Issue Closure')
            || GovernanceIssueLifecycle::query()->visibleTo($user)->exists();
    }

    public static function canView(Model $record): bool
    {
        $user = auth()->user();

        return $user !== null && GovernanceIssueLifecycle::query()->visibleTo($user)->whereKey($record)->exists();
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
        return $schema->components([Section::make('Governance issue lifecycle')->columns(3)->schema([
            TextEntry::make('source_type')->label('Source')->formatStateUsing(fn ($state) => $state->getLabel()),
            TextEntry::make('issue.title')->label('Issue'),
            TextEntry::make('status')->badge(),
            TextEntry::make('issue.owner.name')->label('Issue owner'),
            TextEntry::make('remediationTask.number')->label('Remediation task')->placeholder('Not linked'),
            TextEntry::make('remediationTask.status')->label('Task status')->placeholder('Not linked'),
            TextEntry::make('due_at')->date()->placeholder('Not scheduled'),
            TextEntry::make('verifier.name')->label('Verified by')->placeholder('Not verified'),
            TextEntry::make('closed_at')->dateTime()->placeholder('Open'),
            TextEntry::make('verification_summary')->columnSpanFull()->placeholder('Not verified'),
            TextEntry::make('evidence_reference')->label('Operator evidence reference')->columnSpanFull()->placeholder('None'),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('source_type')->label('Source')->formatStateUsing(fn ($state) => $state->getLabel()),
            TextColumn::make('issue.title')->label('Issue')->wrap(),
            TextColumn::make('status')->badge()->sortable(),
            TextColumn::make('issue.owner.name')->label('Owner'),
            TextColumn::make('remediationTask.number')->label('Remediation')->placeholder('Not linked'),
            TextColumn::make('due_at')->date()->placeholder('Not scheduled')->sortable(),
            TextColumn::make('verifier.name')->label('Verifier')->placeholder('Not verified'),
            TextColumn::make('closed_at')->dateTime()->placeholder('Open')->sortable(),
        ])->filters([SelectFilter::make('status')->options(GovernanceIssueStatus::class)])
            ->headerActions([ExportAction::make()->exporter(GovernanceIssueLifecycleExporter::class)])
            ->recordActions([
                ViewAction::make(),
                Action::make('handoff')->label('Create remediation')->icon('heroicon-o-wrench-screwdriver')
                    ->visible(fn (GovernanceIssueLifecycle $record): bool => $record->status === GovernanceIssueStatus::Open && (auth()->user()?->can('Manage Issue Lifecycle') ?? false))
                    ->schema([
                        Select::make('remediation_project_id')->label('Remediation project')->options(fn (): array => RemediationProject::query()->visibleTo(auth()->user())->orderBy('name')->pluck('name', 'id')->all())->required()->searchable(),
                        Select::make('assignee_id')->label('Assignee')->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
                        Select::make('priority')->options(['Low' => 'Low', 'Medium' => 'Medium', 'High' => 'High', 'Critical' => 'Critical'])->required()->default('Medium'),
                        DatePicker::make('due_date')->required()->minDate(today()),
                        Textarea::make('rationale')->required()->maxLength(30000)->columnSpanFull(),
                    ])->action(fn (GovernanceIssueLifecycle $record, array $data) => self::runAction(fn () => app(GovernanceIssueLifecycleManager::class)->handoff($record->issue, auth()->user(), $data), 'Remediation task created')),
                Action::make('request_verification')->label('Request verification')->icon('heroicon-o-shield-check')
                    ->visible(fn (GovernanceIssueLifecycle $record): bool => $record->status === GovernanceIssueStatus::InRemediation && (auth()->user()?->can('Manage Issue Lifecycle') ?? false))
                    ->schema([Textarea::make('rationale')->required()->maxLength(30000)])
                    ->action(fn (GovernanceIssueLifecycle $record, array $data) => self::runAction(fn () => app(GovernanceIssueLifecycleManager::class)->requestVerification($record->issue, auth()->user(), $data['rationale']), 'Verification requested')),
                Action::make('close')->label('Verify and close')->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (GovernanceIssueLifecycle $record): bool => $record->status === GovernanceIssueStatus::Verification && (auth()->user()?->can('Verify Issue Closure') ?? false))
                    ->schema([
                        Textarea::make('verification_summary')->required()->maxLength(30000),
                        Select::make('evidence_attachment_ids')->label('Accepted closure evidence')
                            ->multiple()->required()->minItems(1)->maxItems(20)->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => self::evidenceOptions($search))
                            ->getOptionLabelsUsing(fn (array $values): array => self::evidenceLabels($values))
                            ->helperText('Select accepted audit-evidence files you are authorized to access. Fynix verifies content presence and records a SHA-256 snapshot.'),
                        Textarea::make('evidence_reference')->label('Operator evidence reference')->maxLength(255),
                    ])->action(fn (GovernanceIssueLifecycle $record, array $data) => self::runAction(fn () => app(GovernanceIssueLifecycleManager::class)->close($record->issue, auth()->user(), $data), 'Issue independently verified and closed')),
                Action::make('reopen')->label('Reopen')->icon('heroicon-o-arrow-uturn-left')->color('danger')
                    ->visible(fn (GovernanceIssueLifecycle $record): bool => $record->status === GovernanceIssueStatus::Closed && (auth()->user()?->can('Manage Issue Lifecycle') ?? false))
                    ->schema([Textarea::make('rationale')->required()->maxLength(30000)])
                    ->action(fn (GovernanceIssueLifecycle $record, array $data) => self::runAction(fn () => app(GovernanceIssueLifecycleManager::class)->reopen($record->issue, auth()->user(), $data['rationale']), 'Issue reopened')),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->withIssueGraph();
        if ($user = auth()->user()) {
            $query->visibleTo($user);
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [TransitionsRelationManager::class, ClosureEvidenceRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListGovernanceIssueLifecycles::route('/'), 'view' => ViewGovernanceIssueLifecycle::route('/{record}')];
    }

    private static function runAction(callable $operation, string $message): void
    {
        $operation();
        Notification::make()->title($message)->success()->send();
    }

    private static function evidenceOptions(string $search): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        return FileAttachment::query()->eligibleClosureEvidenceFor($user)
            ->where('file_name', 'like', '%'.addcslashes($search, '%_').'%')
            ->orderByDesc('id')->limit(50)->pluck('file_name', 'id')->all();
    }

    private static function evidenceLabels(array $values): array
    {
        $user = auth()->user();
        if (! $user) {
            return [];
        }

        return FileAttachment::query()->eligibleClosureEvidenceFor($user)
            ->whereKey($values)->pluck('file_name', 'id')->all();
    }
}
