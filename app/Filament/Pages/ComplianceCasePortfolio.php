<?php

namespace App\Filament\Pages;

use App\ComplianceCases\ComplianceCaseAccessGrantManager;
use App\ComplianceCases\ComplianceCasePortfolioManager;
use App\Models\ComplianceCase;
use App\Support\Enterprise;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\Response;

class ComplianceCasePortfolio extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static string|\UnitEnum|null $navigationGroup = 'Governance';

    protected static ?string $navigationLabel = 'Compliance-case portfolio';

    protected static ?string $title = 'Compliance-case portfolio';

    protected static ?int $navigationSort = 46;

    protected string $view = 'filament.pages.compliance-case-portfolio';

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('compliance_cases');
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return Enterprise::enabled('compliance_cases') && $user !== null
            && ($user->can('viewAny', ComplianceCase::class)
                || ComplianceCaseAccessGrantManager::granteeHasAnyActiveGrant($user));
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $filters = request()->only(['opened_from', 'opened_to']);

        return ['portfolio' => app(ComplianceCasePortfolioManager::class)->summarize(auth()->user(), $filters), 'filters' => $filters];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export_csv')->label(__('Export CSV'))->icon('heroicon-o-arrow-down-tray')
                ->action(fn (): Response => app(ComplianceCasePortfolioManager::class)->downloadCsv(
                    auth()->user(), request()->only(['opened_from', 'opened_to']),
                )),
        ];
    }
}
