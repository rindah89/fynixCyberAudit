<?php

namespace App\Filament\Pages;

use App\Filament\Resources\GovernanceExceptionResource;
use App\Suite\GovernanceOversightService;
use Filament\Pages\Page;

class DataGovernanceOversight extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationLabel = 'Data Governance';

    protected static ?string $title = 'Suite Data Governance';

    protected static string|\UnitEnum|null $navigationGroup = 'Apps';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.data-governance-oversight';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('View Governance Oversight') ?? false;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'report' => app(GovernanceOversightService::class)->report(),
            'exceptionsUrl' => GovernanceExceptionResource::getUrl('index'),
        ];
    }
}
