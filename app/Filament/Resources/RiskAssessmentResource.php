<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RiskAssessmentResource\Pages\ListRiskAssessments;
use App\Filament\Resources\RiskAssessmentResource\Pages\ViewRiskAssessment;
use App\Models\RiskAssessment;
use App\Support\Enterprise;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RiskAssessmentResource extends Resource
{
    protected static ?string $model = RiskAssessment::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static string|\UnitEnum|null $navigationGroup = 'Apps';

    protected static ?string $navigationLabel = 'Risk Assessor';

    protected static ?int $navigationSort = 11;

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('risk_assessor');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('risk_assessor')
            && auth()->user()?->can('Manage Risk Assessments');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('owner.name')->label('Owner'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRiskAssessments::route('/'),
            'view' => ViewRiskAssessment::route('/{record}'),
        ];
    }
}
