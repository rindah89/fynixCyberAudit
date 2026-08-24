<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PrivacyRightsRequestResource\Pages\ListPrivacyRightsRequests;
use App\Filament\Resources\PrivacyRightsRequestResource\Pages\ViewPrivacyRightsRequest;
use App\Filament\Resources\PrivacyRightsRequestResource\RelationManagers\EventsRelationManager;
use App\Models\PrivacyRightsRequest;
use App\Support\Enterprise;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PrivacyRightsRequestResource extends Resource
{
    protected static ?string $model = PrivacyRightsRequest::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Privacy rights requests';

    protected static ?int $navigationSort = 47;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('privacy_management');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', PrivacyRightsRequest::class) === true;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->can('create', PrivacyRightsRequest::class) === true;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['assignee:id,name', 'opener:id,name']);
        $actor = auth()->user();
        if ($actor && ! $actor->can('Read Privacy Rights') && ! $actor->can('Manage Privacy Rights')) {
            $query->where('assigned_to', $actor->id);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('number')->searchable()->sortable(), TextColumn::make('request_type')->badge(),
            TextColumn::make('data_subject_name')->searchable(), TextColumn::make('status')->badge(),
            TextColumn::make('assignee.name')->label('Handler'), TextColumn::make('due_at')->dateTime()->sortable(),
            TextColumn::make('due_state')->badge()->color(fn (string $state): string => match ($state) {
                'overdue' => 'danger', 'complete' => 'success', default => 'info'
            }),
        ])->defaultSort('received_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([Section::make('Sensitive governed rights request')->columns(3)->schema([
            TextEntry::make('number'), TextEntry::make('request_type')->badge(), TextEntry::make('status')->badge(),
            TextEntry::make('data_subject_name'), TextEntry::make('data_subject_email')->placeholder('Not supplied'), TextEntry::make('subject_reference')->placeholder('Not supplied'),
            TextEntry::make('assignee.name')->label('Handler'), TextEntry::make('opener.name')->label('Opened by'), TextEntry::make('intake_channel'),
            TextEntry::make('received_at')->dateTime(), TextEntry::make('due_at')->dateTime(), TextEntry::make('completed_at')->dateTime()->placeholder('Open'),
            TextEntry::make('request_details')->columnSpanFull(), TextEntry::make('jurisdiction_reference')->columnSpanFull()->placeholder('Not supplied'),
            TextEntry::make('identity_verification_summary')->columnSpanFull()->placeholder('Not recorded'), TextEntry::make('response_summary')->columnSpanFull()->placeholder('Not recorded'),
            TextEntry::make('decision_basis')->columnSpanFull()->placeholder('Not recorded'), TextEntry::make('delivery_reference')->columnSpanFull()->placeholder('Not recorded'),
        ])]);
    }

    public static function getRelations(): array
    {
        return [EventsRelationManager::class];
    }

    public static function getPages(): array
    {
        return ['index' => ListPrivacyRightsRequests::route('/'), 'view' => ViewPrivacyRightsRequest::route('/{record}')];
    }
}
