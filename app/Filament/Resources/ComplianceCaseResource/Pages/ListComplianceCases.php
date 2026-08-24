<?php

namespace App\Filament\Resources\ComplianceCaseResource\Pages;

use App\ComplianceCases\ComplianceCaseManager;
use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use App\Filament\Resources\ComplianceCaseResource;
use App\Models\ComplianceCase;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;

class ListComplianceCases extends ListRecords
{
    protected static string $resource = ComplianceCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open_case')->label('Open governed case')->icon('heroicon-o-plus')
                ->visible(fn (): bool => auth()->user()?->can('create', ComplianceCase::class) === true)
                ->schema([
                    TextInput::make('title')->required()->maxLength(255),
                    Select::make('category')->options(ComplianceCaseCategory::class)->required(),
                    Select::make('priority')->options(ComplianceCasePriority::class)->required(),
                    Textarea::make('allegation')->required()->maxLength(30000)->columnSpanFull(),
                    TextInput::make('source_channel')->maxLength(100), TextInput::make('reporter_reference')->maxLength(255),
                    Textarea::make('source_reference')->maxLength(2000)->columnSpanFull(), Toggle::make('confidential')->default(true),
                    Textarea::make('summary')->label('Opening rationale')->required()->maxLength(30000)->columnSpanFull(),
                ])->action(fn (array $data) => app(ComplianceCaseManager::class)->open(auth()->user(), $data)),
        ];
    }
}
