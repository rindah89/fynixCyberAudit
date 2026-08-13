<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RemediationProjectResource\Pages\ListRemediationProjects;
use App\Filament\Resources\RemediationProjectResource\Pages\ViewRemediationProject;
use App\Models\RemediationProject;
use App\Support\Enterprise;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class RemediationProjectResource extends Resource
{
    protected static ?string $model = RemediationProject::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|\UnitEnum|null $navigationGroup = 'Remediation';

    protected static ?string $navigationLabel = 'Remediation';

    protected static ?int $navigationSort = 20;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('remediation');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('remediation')
            && auth()->user()?->can('Manage Remediation');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if ($user) {
            $query->visibleTo($user);
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('owner.name')->label('Owner'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRemediationProjects::route('/'),
            'view' => ViewRemediationProject::route('/{record}'),
        ];
    }
}
