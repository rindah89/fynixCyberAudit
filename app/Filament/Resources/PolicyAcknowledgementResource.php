<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PolicyAcknowledgementResource\Pages\ListPolicyAcknowledgements;
use App\Filament\Resources\PolicyAcknowledgementResource\Pages\ViewPolicyAcknowledgement;
use App\Models\PolicyAcknowledgementAssignment;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PolicyAcknowledgementResource extends Resource
{
    protected static ?string $model = PolicyAcknowledgementAssignment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Compliance';

    protected static ?string $navigationLabel = 'My Policy Acknowledgements';

    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function canView(Model $record): bool
    {
        return $record->user_id === auth()->id();
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
            TextColumn::make('campaign.policy.code')->label('Policy')->searchable(),
            TextColumn::make('campaign.policy.name')->label('Policy name')->searchable(),
            TextColumn::make('campaign.title')->label('Campaign'),
            TextColumn::make('acknowledgement_status')->label('Status')->badge()->color(fn (string $state): string => self::statusColor($state)),
            TextColumn::make('campaign.due_at')->label('Due')->dateTime()->sortable(),
            TextColumn::make('reminders_count')->label('Reminders')->counts('reminders'),
            TextColumn::make('acknowledgement.acknowledged_at')->label('Acknowledged')->dateTime()->placeholder('Pending'),
        ])->defaultSort('assigned_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Assigned policy version')->columns(3)->schema([
                TextEntry::make('campaign.policy_snapshot.code')->label('Policy code'),
                TextEntry::make('campaign.policy_snapshot.name')->label('Policy name'),
                TextEntry::make('campaign.version')->label('Campaign version'),
                TextEntry::make('acknowledgement_status')->label('Status')->badge()->color(fn (string $state): string => self::statusColor($state)),
                TextEntry::make('campaign.due_at')->label('Due')->dateTime(),
                TextEntry::make('campaign.policy_fingerprint')->label('Policy fingerprint')->copyable()->columnSpanFull(),
                TextEntry::make('campaign.instructions')->placeholder('No additional instructions')->columnSpanFull(),
                TextEntry::make('campaign.policy_snapshot.purpose')->html()->columnSpanFull(),
                TextEntry::make('campaign.policy_snapshot.body')->html()->columnSpanFull(),
                TextEntry::make('campaign.policy_snapshot.document_path')->label('Document reference')->placeholder('Embedded policy content')->columnSpanFull(),
            ]),
            Section::make('Acknowledgement evidence')->columns(2)->schema([
                TextEntry::make('acknowledgement.statement')->placeholder('Not acknowledged')->columnSpanFull(),
                TextEntry::make('acknowledgement.comment')->placeholder('No comment')->columnSpanFull(),
                TextEntry::make('acknowledgement.acknowledged_at')->dateTime()->placeholder('Not acknowledged'),
                TextEntry::make('acknowledgement.client_reference')->placeholder('Not provided'),
            ]),
            Section::make('In-app delivery evidence')->columns(2)->schema([
                TextEntry::make('delivery.delivered_at')->label('Assignment delivered')->dateTime()->placeholder('Not delivered'),
                TextEntry::make('delivery.notification_id')->label('Assignment notification ID')->copyable()->placeholder('Not delivered'),
                TextEntry::make('delivery.fingerprint')->label('Assignment delivery fingerprint')->copyable()->columnSpanFull()->placeholder('Not delivered'),
                RepeatableEntry::make('reminders')->schema([
                    TextEntry::make('type')->label('Reminder')->badge()
                        ->formatStateUsing(fn ($state): string => $state->getLabel())
                        ->color(fn ($state): string => $state->getColor()),
                    TextEntry::make('delivered_at')->label('Delivered')->dateTime(),
                    TextEntry::make('notification_id')->label('Notification ID')->copyable(),
                    TextEntry::make('fingerprint')->label('Fingerprint')->copyable()->columnSpanFull(),
                    TextEntry::make('recipient_snapshot')->label('Recipient snapshot')
                        ->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
                    TextEntry::make('campaign_snapshot')->label('Campaign snapshot')
                        ->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))->columnSpanFull(),
                ])->columnSpanFull(),
                TextEntry::make('escalation.delivered_at')->label('Policy-owner escalation delivered')->dateTime()->placeholder('Not escalated'),
                TextEntry::make('escalation.notification_id')->label('Escalation notification ID')->copyable()->placeholder('Not escalated'),
                TextEntry::make('escalation.fingerprint')->label('Escalation fingerprint')->copyable()->columnSpanFull()->placeholder('Not escalated'),
                RepeatableEntry::make('knowledgeCheckAttempts')->label('Comprehension-check attempts')->schema([
                    TextEntry::make('version'), TextEntry::make('score_percentage')->suffix('%'),
                    TextEntry::make('passed')->badge()->formatStateUsing(fn (bool $state): string => $state ? 'Passed' : 'Not passed')
                        ->color(fn (bool $state): string => $state ? 'success' : 'danger'),
                    TextEntry::make('submitted_at')->dateTime(),
                    TextEntry::make('answers_snapshot')->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR))->columnSpanFull(),
                    TextEntry::make('fingerprint')->copyable()->columnSpanFull(),
                ])->columnSpanFull(),
            ]),
        ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('user_id', auth()->id())
            ->with(['campaign.policy:id,code,name', 'delivery', 'reminders', 'escalation', 'knowledgeCheckAttempts', 'acknowledgement']);
    }

    public static function getPages(): array
    {
        return ['index' => ListPolicyAcknowledgements::route('/'), 'view' => ViewPolicyAcknowledgement::route('/{record}')];
    }

    private static function statusColor(string $state): string
    {
        return match ($state) {
            'acknowledged' => 'success', 'pending' => 'warning', 'overdue' => 'danger', default => 'gray',
        };
    }
}
