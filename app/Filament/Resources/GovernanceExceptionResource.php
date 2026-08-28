<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GovernanceExceptionResource\Pages\EditGovernanceException;
use App\Filament\Resources\GovernanceExceptionResource\Pages\ListGovernanceExceptions;
use App\Models\GovernanceException;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GovernanceExceptionResource extends Resource
{
    protected static ?string $model = GovernanceException::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = 'Governance Exceptions';

    protected static string|\UnitEnum|null $navigationGroup = 'Apps';

    protected static ?int $navigationSort = 2;

    public static function canViewAny(): bool
    {
        return auth()->user()?->can('View Governance Oversight') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->can('Manage Governance Exceptions') ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('source')->disabled(),
            TextInput::make('tenant_id')->disabled(),
            TextInput::make('control_id')->disabled(),
            Textarea::make('reason')->disabled()->columnSpanFull(),
            Select::make('status')->options([
                'open' => 'Open',
                'waived' => 'Waived',
                'resolved' => 'Resolved',
            ])->required(),
            TextInput::make('owner')->required()->maxLength(255),
            DateTimePicker::make('due_at')->required(),
            Textarea::make('resolution_notes')
                ->label('Management rationale / resolution evidence')
                ->required()
                ->maxLength(65535)
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('last_detected_at', 'desc')
            ->columns([
                TextColumn::make('source')->searchable()->sortable(),
                TextColumn::make('control_id')->label('Control')->searchable()->sortable(),
                TextColumn::make('severity')->badge()->sortable(),
                TextColumn::make('status')->badge()->sortable(),
                TextColumn::make('owner')->placeholder('Unassigned')->searchable(),
                TextColumn::make('due_at')->dateTime()->placeholder('Not set')->sortable(),
                TextColumn::make('last_detected_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('source')->options(fn (): array => collect(config('data_governance.required_sources', []))->mapWithKeys(fn (string $source): array => [$source => strtoupper($source)])->all()),
                SelectFilter::make('status')->options(['open' => 'Open', 'waived' => 'Waived', 'resolved' => 'Resolved']),
                SelectFilter::make('severity')->options(['high' => 'High', 'medium' => 'Medium', 'low' => 'Low']),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGovernanceExceptions::route('/'),
            'edit' => EditGovernanceException::route('/{record}/edit'),
        ];
    }
}
