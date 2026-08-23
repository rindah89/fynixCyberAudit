<?php

namespace App\Filament\Exports;

use Aliziodev\LaravelTaxonomy\Models\Taxonomy;
use App\Models\Risk;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class RiskExporter extends Exporter
{
    protected static ?string $model = Risk::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('code')
                ->label('Code'),
            ExportColumn::make('name')
                ->label('Name'),
            ExportColumn::make('description')
                ->label('Description'),
            ExportColumn::make('domain')
                ->label('Risk Domain')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('status')
                ->label('Status')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('inherent_likelihood')
                ->label('Inherent Likelihood'),
            ExportColumn::make('inherent_impact')
                ->label('Inherent Impact'),
            ExportColumn::make('inherent_risk')
                ->label('Inherent Risk'),
            ExportColumn::make('residual_likelihood')
                ->label('Residual Likelihood'),
            ExportColumn::make('residual_impact')
                ->label('Residual Impact'),
            ExportColumn::make('residual_risk')
                ->label('Residual Risk'),
            ExportColumn::make('portfolio_governance_status')
                ->label('Portfolio Governance Status'),
            ExportColumn::make('governanceProfile.owner.name')
                ->label('Accountable Owner'),
            ExportColumn::make('governanceProfile.appetite_threshold')
                ->label('Appetite Threshold'),
            ExportColumn::make('governanceProfile.review_frequency')
                ->label('Review Frequency')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('governanceProfile.businessService.name')
                ->label('Business Service'),
            ExportColumn::make('latestGovernanceReview.decision')
                ->label('Latest Governance Decision')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('latestGovernanceReview.next_review_at')
                ->label('Next Governance Review'),
            ExportColumn::make('parentRisk.code')
                ->label('Parent Risk Code'),
            ExportColumn::make('child_risks_count')
                ->label('Direct Child Risks'),
            ExportColumn::make('enterprise_scenarios_count')
                ->label('Enterprise Scenarios'),
            ExportColumn::make('latestEnterpriseScenario.stressed_score_sum')
                ->label('Latest Scenario Stressed Score Sum'),
            ExportColumn::make('latestEnterpriseScenario.score_delta')
                ->label('Latest Scenario Score Delta'),
            ExportColumn::make('latestEnterpriseScenario.probability_band')
                ->label('Latest Scenario Probability Band')
                ->formatStateUsing(fn ($state) => $state?->getLabel() ?? ''),
            ExportColumn::make('department')
                ->label('Department')
                ->state(fn (Risk $record): ?string => $record->taxonomies->first(fn (Taxonomy $taxonomy): bool => $taxonomy->parent?->slug === 'department')?->name),
            ExportColumn::make('scope')
                ->label('Scope')
                ->state(fn (Risk $record): ?string => $record->taxonomies->first(fn (Taxonomy $taxonomy): bool => $taxonomy->parent?->slug === 'scope')?->name),
            ExportColumn::make('is_active')
                ->label('Active'),
            ExportColumn::make('created_at')
                ->label('Created At'),
            ExportColumn::make('updated_at')
                ->label('Updated At'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->withPortfolioGovernanceGraph()->with('taxonomies.parent');
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your risk export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
