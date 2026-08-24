<?php

namespace App\Filament\Vendor\Resources;

use App\Enums\ThirdPartyCollaborationStatus;
use App\Enums\ThirdPartyEngagementStatus;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ListCollaborationRequests;
use App\Filament\Vendor\Resources\CollaborationRequestResource\Pages\ViewCollaborationRequest;
use App\Models\ThirdPartyEngagementCollaborationEvidence;
use App\Models\ThirdPartyEngagementCollaborationReminder;
use App\Models\ThirdPartyEngagementCollaborationRequest;
use App\Models\VendorDocument;
use App\Models\VendorUser;
use App\ThirdPartyRisk\ThirdPartyEngagementCollaborationManager;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class CollaborationRequestResource extends Resource
{
    protected static ?string $model = ThirdPartyEngagementCollaborationRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Collaboration requests';

    protected static ?int $navigationSort = 3;

    public static function getEloquentQuery(): Builder
    {
        $actor = self::vendorActor();

        return parent::getEloquentQuery()
            ->where('recipient_vendor_user_id', $actor->id)
            ->with([
                'engagement:id,code,name,status',
                'opener:id,name,email',
                'events.evidence' => fn ($query) => $query
                    ->whereHas('document', fn ($documentQuery) => $documentQuery
                        ->where('vendor_id', $actor->vendor_id)
                        ->whereNull('deleted_at'))
                    ->with('document'),
                'latestEvent',
                'reminders',
                'escalation:id,third_party_engagement_collaboration_request_id,channel,delivered_at,fingerprint',
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('engagement.code')->label('Engagement')->searchable(),
            TextColumn::make('subject')->searchable()->wrap(),
            TextColumn::make('category')->badge(),
            TextColumn::make('latest_status')->label('Status')->state(fn (ThirdPartyEngagementCollaborationRequest $record) => $record->latestStatus())->badge(),
            TextColumn::make('reminder_state')->label('Reminder')->state(fn (ThirdPartyEngagementCollaborationRequest $record) => $record->reminders->last()?->type)->badge(),
            TextColumn::make('due_at')->date()->sortable(),
        ])->recordActions([
            ViewAction::make(),
            Action::make('respond')->label('Respond')->icon('heroicon-o-paper-airplane')
                ->visible(fn (ThirdPartyEngagementCollaborationRequest $record): bool => in_array($record->engagementStatus(), [ThirdPartyEngagementStatus::DueDiligence, ThirdPartyEngagementStatus::Approved, ThirdPartyEngagementStatus::Active, ThirdPartyEngagementStatus::RenewalReview], true)
                    && in_array($record->latestStatus(), [ThirdPartyCollaborationStatus::Requested, ThirdPartyCollaborationStatus::FollowUp], true))
                ->schema([
                    Textarea::make('response_text')->required()->maxLength(30000)->columnSpanFull(),
                    TextInput::make('source_reference')->maxLength(255),
                    Select::make('vendor_document_ids')->label('Supporting documents')->multiple()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => self::documentOptions($search))
                        ->getOptionLabelsUsing(fn (array $values): array => self::documentOptions('', array_map('intval', $values))),
                ])
                ->action(fn (ThirdPartyEngagementCollaborationRequest $record, array $data) => app(ThirdPartyEngagementCollaborationManager::class)->respond(self::vendorActor(), $record, $data)),
        ])->defaultSort('opened_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Request')->schema([
                TextEntry::make('engagement.code')->label('Engagement'), TextEntry::make('category')->badge(), TextEntry::make('subject'),
                TextEntry::make('request_text')->columnSpanFull(), TextEntry::make('due_at')->date(), TextEntry::make('opener.name')->label('Opened by'),
                TextEntry::make('fingerprint')->columnSpanFull(),
            ])->columns(2),
            Section::make('Append-only history')->schema([
                RepeatableEntry::make('events')->hiddenLabel()->schema([
                    TextEntry::make('version'), TextEntry::make('status')->badge(), TextEntry::make('actor_snapshot.name')->label('Actor'),
                    TextEntry::make('recorded_at')->dateTime(), TextEntry::make('response_text')->columnSpanFull(),
                    TextEntry::make('source_reference'), TextEntry::make('summary')->columnSpanFull(), TextEntry::make('fingerprint')->columnSpanFull(),
                    RepeatableEntry::make('evidence')->schema([
                        TextEntry::make('file_name_snapshot')->label('Retained file')->url(fn (ThirdPartyEngagementCollaborationEvidence $record): string => route('vendor.third-party-collaboration-evidence.download', $record))->openUrlInNewTab(),
                        TextEntry::make('file_size_snapshot')->numeric(), TextEntry::make('sha256'),
                    ])->columns(2)->columnSpanFull(),
                ])->columns(2),
            ]),
            Section::make('In-app reminder delivery evidence')->schema([
                RepeatableEntry::make('reminders')->hiddenLabel()->schema([
                    TextEntry::make('type')->badge(), TextEntry::make('channel'), TextEntry::make('delivered_at')->dateTime(),
                    TextEntry::make('attempted_at')->dateTime(), TextEntry::make('notification_id')->columnSpanFull(),
                    TextEntry::make('recipient_snapshot')->state(fn (ThirdPartyEngagementCollaborationReminder $record): string => json_encode($record->recipient_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                    TextEntry::make('request_snapshot')->state(fn (ThirdPartyEngagementCollaborationReminder $record): string => json_encode($record->request_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                    TextEntry::make('event_snapshot')->state(fn (ThirdPartyEngagementCollaborationReminder $record): string => json_encode($record->event_snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))->columnSpanFull(),
                    TextEntry::make('fingerprint')->columnSpanFull(),
                ])->columns(3),
            ]),
            Section::make('Escalated internally')->schema([
                TextEntry::make('escalation.channel')->label('Channel'),
                TextEntry::make('escalation.delivered_at')->label('Delivered')->dateTime(),
                TextEntry::make('escalation.fingerprint')->label('Evidence fingerprint')->columnSpanFull(),
            ])->visible(fn (ThirdPartyEngagementCollaborationRequest $record): bool => $record->escalation !== null),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListCollaborationRequests::route('/'), 'view' => ViewCollaborationRequest::route('/{record}')];
    }

    public static function canCreate(): bool
    {
        return false;
    }

    private static function vendorActor(): VendorUser
    {
        /** @var VendorUser $actor */
        $actor = Auth::guard('vendor')->user();

        return $actor;
    }

    /** @param list<int> $ids */
    private static function documentOptions(string $search, array $ids = []): array
    {
        $actor = self::vendorActor();

        return VendorDocument::query()->where('vendor_id', $actor->vendor_id)->whereNull('deleted_at')
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [] && $search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }
}
