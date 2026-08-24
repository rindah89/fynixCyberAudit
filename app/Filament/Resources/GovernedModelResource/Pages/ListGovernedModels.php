<?php

namespace App\Filament\Resources\GovernedModelResource\Pages;

use App\Filament\Resources\GovernedModelResource;
use App\ModelRisk\ModelRiskManager;
use App\Models\GovernedModel;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListGovernedModels extends ListRecords
{
    protected static string $resource = GovernedModelResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('register')->label('Register governed model')->icon('heroicon-o-plus')->visible(fn (): bool => auth()->user()?->can('create', GovernedModel::class) === true)->schema(self::modelSchema())->action(fn (array $data) => app(ModelRiskManager::class)->register(auth()->user(), $data))];
    }

    public static function modelSchema(): array
    {
        return [TextInput::make('name')->required()->maxLength(255), Select::make('model_type')->options(array_combine(['Credit', 'Market', 'Financial', 'Operational', 'Compliance', 'Decision Support', 'Statistical', 'Other'], ['Credit', 'Market', 'Financial', 'Operational', 'Compliance', 'Decision Support', 'Statistical', 'Other']))->required(), Select::make('tier')->options([1 => 'Tier 1', 2 => 'Tier 2', 3 => 'Tier 3', 4 => 'Tier 4'])->required(), Select::make('owner_id')->label('Owner')->searchable()->options(fn (): array => User::permission(['Own Governed Models', 'Manage Model Risk'])->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all())->required(), Select::make('developer_id')->label('Developer')->searchable()->options(fn (): array => User::permission(['Develop Governed Models', 'Manage Model Risk'])->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all())->required(), Textarea::make('intended_use')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('methodology')->required()->maxLength(30000)->columnSpanFull(), TagsInput::make('input_data')->required(), TagsInput::make('outputs')->required(), TagsInput::make('assumptions'), TagsInput::make('limitations')->required(), TagsInput::make('usage_restrictions'), Textarea::make('implementation_reference')->maxLength(2000), TextInput::make('change_frequency')->required()->maxLength(255), DatePicker::make('next_review_at')->required()->minDate(today()), Textarea::make('change_summary')->required()->maxLength(30000)->columnSpanFull()];
    }
}
