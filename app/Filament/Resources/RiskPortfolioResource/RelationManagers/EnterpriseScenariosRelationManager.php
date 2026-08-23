<?php

namespace App\Filament\Resources\RiskPortfolioResource\RelationManagers;

use App\Enums\EnterpriseScenarioProbability;
use App\Enums\RiskDomain;
use App\Models\EnterpriseRiskScenario;
use App\Models\Risk;
use App\Services\EnterpriseRiskHierarchy;
use App\Services\EnterpriseRiskScenarioAnalyzer;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class EnterpriseScenariosRelationManager extends RelationManager
{
    protected static string $relationship = 'enterpriseScenarios';

    protected static ?string $title = 'Enterprise stress scenarios';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Risk && $ownerRecord->domain === RiskDomain::Enterprise;
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with('creator:id,name'))
            ->defaultSort('version', 'desc')->columns([
                TextColumn::make('version')->sortable(),
                TextColumn::make('name')->searchable()->wrap(),
                TextColumn::make('probability_band')->badge()->color('gray'),
                TextColumn::make('stressed_score_sum')->label('Stressed total'),
                TextColumn::make('score_delta')->label('Delta'),
                TextColumn::make('above_appetite_count')->label('Above appetite'),
                TextColumn::make('creator.name')->label('Analyzed by')->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') || auth()->user()?->can('Read Risks')),
                TextColumn::make('analyzed_at')->dateTime()->sortable(),
            ])->headerActions([
                Action::make('run_scenario')->label('Run scenario')->icon('heroicon-o-beaker')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') ?? false)
                    ->schema([
                        TextInput::make('name')->required()->maxLength(255),
                        Textarea::make('narrative')->required()->maxLength(30000)->columnSpanFull(),
                        TextInput::make('horizon_months')->label('Horizon (months)')->numeric()->integer()->minValue(1)->maxValue(120)->required()->default(12),
                        Select::make('probability_band')->options(EnterpriseScenarioProbability::class)->required(),
                        Repeater::make('adjustments')->required()->minItems(1)->maxItems(10000)->schema([
                            Select::make('risk_id')->label('Enterprise risk')->options(function (): array {
                                $root = $this->getOwnerRecord();
                                $ids = [$root->id, ...app(EnterpriseRiskHierarchy::class)->descendantIds($root->id)];

                                return Risk::query()->whereKey($ids)->where('is_active', true)->whereHas('governanceProfile')->orderBy('name')->pluck('name', 'id')->all();
                            })->searchable()->required()->distinct(),
                            TextInput::make('likelihood_shift')->numeric()->integer()->minValue(-4)->maxValue(4)->required()->default(0),
                            TextInput::make('impact_shift')->numeric()->integer()->minValue(-4)->maxValue(4)->required()->default(0),
                            Textarea::make('rationale')->maxLength(30000)->columnSpanFull(),
                        ])->columns(3)->columnSpanFull(),
                    ])->action(function (array $data): void {
                        app(EnterpriseRiskScenarioAnalyzer::class)->analyze($this->getOwnerRecord(), auth()->user(), $data);
                        Notification::make()->title('Enterprise scenario analyzed')->success()->send();
                    }),
            ])->recordActions([
                Action::make('inspect_items')->label('Inspect items')->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (): bool => auth()->user()?->can('Manage Risk Portfolio') || auth()->user()?->can('Read Risks'))
                    ->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(function (EnterpriseRiskScenario $record): HtmlString {
                        $items = $record->items()->orderBy('id')->limit(100)->get();
                        $rows = $items->map(fn ($item): string => '<tr class="border-b">'
                            .'<td class="p-2">'.e($item->risk_code_snapshot).'</td><td class="p-2">'.e($item->risk_name_snapshot).'</td>'
                            .'<td class="p-2">'.e((string) ($item->parent_risk_id_snapshot ?? 'Root')).'</td><td class="p-2">'.e((string) ($item->owner_id_snapshot ?? 'Unassigned')).'</td>'
                            .'<td class="p-2">'.e((string) $item->appetite_threshold_snapshot).'</td><td class="p-2">'.e("{$item->baseline_likelihood} × {$item->baseline_impact} = {$item->baseline_score}").'</td>'
                            .'<td class="p-2">'.e((string) $item->likelihood_shift).'</td><td class="p-2">'.e((string) $item->impact_shift).'</td>'
                            .'<td class="p-2">'.e("{$item->stressed_likelihood} × {$item->stressed_impact} = {$item->stressed_score}").'</td><td class="p-2">'.e($item->rationale ?? '—').'</td></tr>')->implode('');
                        $total = $record->items()->count();
                        $notice = $total > 100 ? '<p class="mb-3 text-sm text-gray-500">Showing the first 100 of '.e((string) $total).' items. Use REST or MCP pagination for the remaining snapshots.</p>' : '';

                        return new HtmlString($notice.'<div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="border-b text-left"><th class="p-2">Risk</th><th class="p-2">Name snapshot</th><th class="p-2">Parent ID</th><th class="p-2">Owner ID</th><th class="p-2">Appetite</th><th class="p-2">Baseline L × I</th><th class="p-2">Likelihood shift</th><th class="p-2">Impact shift</th><th class="p-2">Stressed L × I</th><th class="p-2">Rationale</th></tr></thead><tbody>'.$rows.'</tbody></table></div>');
                    }),
            ])->toolbarActions([]);
    }
}
