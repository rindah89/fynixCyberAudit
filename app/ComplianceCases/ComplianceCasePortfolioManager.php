<?php

namespace App\ComplianceCases;

use App\Enums\ComplianceCaseMilestoneStatus;
use App\Enums\ComplianceCaseStatus;
use App\Enums\GovernanceIssueStatus;
use App\Models\ComplianceCase;
use App\Models\ComplianceCaseActionIssue;
use App\Models\ComplianceCaseArchiveReview;
use App\Models\ComplianceCaseClosureReportReview;
use App\Models\ComplianceCaseDispositionReview;
use App\Models\ComplianceCaseLegalHold;
use App\Models\ComplianceCaseMilestone;
use App\Models\ComplianceCaseReopenReview;
use App\Models\User;
use App\Support\Enterprise;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ComplianceCasePortfolioManager
{
    /**
     * @param  array{opened_from?:string,opened_to?:string}  $filters
     * @return array{
     *     total:int,
     *     by_status:array<string,int>,
     *     by_phase:array<string,int>,
     *     age_bands:array<string,int>,
     *     overdue_milestones:int,
     *     open_holds:int,
     *     open_issues:int,
     *     closed:int,
     *     reopened:int,
     *     review_outcomes:array<string,int>,
     *     average_open_days:float|null
     * }
     */
    public function summarize(User $actor, array $filters = []): array
    {
        Enterprise::assertEnabled('compliance_cases');
        abort_unless(
            $actor->can('viewAny', ComplianceCase::class)
            || ComplianceCaseAccessGrantManager::granteeHasAnyActiveGrant($actor),
            403,
        );
        $asOf = now()->utc();
        $filters = $this->boundedWindow($filters, $asOf);
        $query = ComplianceCase::query();
        ComplianceCaseAccessGrantManager::scopeVisibleTo($query, $actor);
        $this->applyOpenedWindow($query, $filters);
        $rows = $query->limit(10001)->get(['id', 'status', 'opened_at', 'closed_at']);
        if ($rows->count() > 10000) {
            throw ValidationException::withMessages(['portfolio' => 'The selected portfolio exceeds the 10,000 visible-case bound. Narrow the date window.']);
        }
        $empty = $this->emptySummary();
        if ($rows->isEmpty()) {
            return $empty;
        }

        $byStatus = $empty['by_status'];
        $byPhase = $empty['by_phase'];
        $ageBands = $empty['age_bands'];
        $openDays = [];
        $closed = 0;
        $reopened = 0;
        foreach ($rows as $row) {
            $status = $row->status instanceof \BackedEnum ? $row->status->value : (string) $row->status;
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $phase = $this->phaseFor($row->status);
            $byPhase[$phase]++;
            if ($row->status === ComplianceCaseStatus::Closed) {
                $closed++;

                continue;
            }
            if ($row->closed_at !== null) {
                $reopened++;
            }
            $opened = $row->opened_at?->copy()->utc() ?? $asOf;
            $days = (int) $opened->startOfDay()->diffInDays($asOf->copy()->startOfDay());
            $openDays[] = $days;
            $band = match (true) {
                $days <= 7 => '0_7',
                $days <= 30 => '8_30',
                $days <= 90 => '31_90',
                default => '91_plus',
            };
            $ageBands[$band]++;
        }

        $ids = $rows->pluck('id');
        $overdue = ComplianceCaseMilestone::query()->whereIn('compliance_case_id', $ids)
            ->where('status', ComplianceCaseMilestoneStatus::Open->value)
            ->where('due_at', '<', $asOf)->count();
        $openHolds = ComplianceCaseLegalHold::query()->whereIn('compliance_case_id', $ids)->whereDoesntHave('release')->count();
        $openIssues = ComplianceCaseActionIssue::query()->whereIn('compliance_case_id', $ids)
            ->whereHas('lifecycle', fn ($lifecycle) => $lifecycle->where('status', '!=', GovernanceIssueStatus::Closed->value))
            ->count();
        $reviews = $empty['review_outcomes'];
        foreach ([
            ComplianceCaseClosureReportReview::query()->whereHas('closureReport', fn ($report) => $report->whereIn('compliance_case_id', $ids)),
            ComplianceCaseArchiveReview::query()->whereHas('manifest', fn ($manifest) => $manifest->whereIn('compliance_case_id', $ids)),
            ComplianceCaseDispositionReview::query()->whereHas('classification', fn ($classification) => $classification->whereIn('compliance_case_id', $ids)),
            ComplianceCaseReopenReview::query()->whereHas('proposal', fn ($proposal) => $proposal->whereIn('compliance_case_id', $ids)),
        ] as $reviewQuery) {
            foreach ($reviewQuery->selectRaw('decision, COUNT(*) as aggregate')->groupBy('decision')->pluck('aggregate', 'decision') as $decision => $count) {
                $reviews[(string) $decision] = ($reviews[(string) $decision] ?? 0) + (int) $count;
            }
        }

        return [
            'total' => $rows->count(),
            'by_status' => $byStatus,
            'by_phase' => $byPhase,
            'age_bands' => $ageBands,
            'overdue_milestones' => $overdue,
            'open_holds' => $openHolds,
            'open_issues' => $openIssues,
            'closed' => $closed,
            'reopened' => $reopened,
            'review_outcomes' => $reviews,
            'average_open_days' => $openDays === [] ? null : round(array_sum($openDays) / count($openDays), 1),
        ];
    }

    /**
     * @param  array{opened_from?:string,opened_to?:string}  $filters
     */
    public function downloadCsv(User $actor, array $filters = []): Response
    {
        $summary = $this->summarize($actor, $filters);
        $rows = [
            ['metric', 'label', 'value'],
            ['total', 'Visible cases', (string) $summary['total']],
            ['closed', 'Closed cases', (string) $summary['closed']],
            ['reopened', 'Reopened cases', (string) $summary['reopened']],
            ['overdue_milestones', 'Overdue open milestones', (string) $summary['overdue_milestones']],
            ['open_holds', 'Unreleased legal holds', (string) $summary['open_holds']],
            ['open_issues', 'Open action issues', (string) $summary['open_issues']],
            ['average_open_days', 'Average open age in days', $summary['average_open_days'] === null ? '' : (string) $summary['average_open_days']],
        ];
        foreach ($summary['by_status'] as $status => $count) {
            $rows[] = ['by_status.'.$status, 'Cases in '.$status, (string) $count];
        }
        foreach ($summary['by_phase'] as $phase => $count) {
            $rows[] = ['by_phase.'.$phase, 'Cases in phase '.$phase, (string) $count];
        }
        foreach ([
            '0_7' => 'Open 0-7 days', '8_30' => 'Open 8-30 days',
            '31_90' => 'Open 31-90 days', '91_plus' => 'Open 91+ days',
        ] as $band => $label) {
            $rows[] = ['age_bands.'.$band, $label, (string) $summary['age_bands'][$band]];
        }
        foreach ($summary['review_outcomes'] as $decision => $count) {
            $rows[] = ['review_outcomes.'.$decision, 'Review decision '.$decision, (string) $count];
        }
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="compliance-case-portfolio.csv"',
        ]);
    }

    /**
     * @return array{
     *     total:int,
     *     by_status:array<string,int>,
     *     by_phase:array<string,int>,
     *     age_bands:array<string,int>,
     *     overdue_milestones:int,
     *     open_holds:int,
     *     open_issues:int,
     *     closed:int,
     *     reopened:int,
     *     review_outcomes:array<string,int>,
     *     average_open_days:null
     * }
     */
    private function emptySummary(): array
    {
        $byStatus = [];
        foreach (ComplianceCaseStatus::cases() as $status) {
            $byStatus[$status->value] = 0;
        }

        return [
            'total' => 0,
            'by_status' => $byStatus,
            'by_phase' => ['intake' => 0, 'investigation' => 0, 'remediation' => 0, 'resolution' => 0, 'terminal' => 0],
            'age_bands' => ['0_7' => 0, '8_30' => 0, '31_90' => 0, '91_plus' => 0],
            'overdue_milestones' => 0,
            'open_holds' => 0,
            'open_issues' => 0,
            'closed' => 0,
            'reopened' => 0,
            'review_outcomes' => ['approved' => 0, 'rejected' => 0, 'deferred' => 0],
            'average_open_days' => null,
        ];
    }

    /** @param array{opened_from?:string,opened_to?:string} $filters */
    private function applyOpenedWindow(Builder $query, array $filters): void
    {
        if (! empty($filters['opened_from'])) {
            $query->where('opened_at', '>=', Carbon::parse($filters['opened_from'], 'UTC')->startOfDay());
        }
        if (! empty($filters['opened_to'])) {
            $query->where('opened_at', '<=', Carbon::parse($filters['opened_to'], 'UTC')->endOfDay());
        }
    }

    /**
     * @param  array{opened_from?:string,opened_to?:string}  $filters
     * @return array{opened_from:string,opened_to:string}
     */
    private function boundedWindow(array $filters, Carbon $asOf): array
    {
        $from = Carbon::parse($filters['opened_from'] ?? $asOf->copy()->subDays(365)->toDateString(), 'UTC')->startOfDay();
        $to = Carbon::parse($filters['opened_to'] ?? $asOf->toDateString(), 'UTC')->endOfDay();
        if ($to->lt($from) || $from->copy()->addDays(366)->lt($to)) {
            throw ValidationException::withMessages(['opened_to' => 'The portfolio window must be ordered and no longer than 366 days.']);
        }

        return ['opened_from' => $from->toDateString(), 'opened_to' => $to->toDateString()];
    }

    private function phaseFor(ComplianceCaseStatus $status): string
    {
        return match ($status) {
            ComplianceCaseStatus::New, ComplianceCaseStatus::Triaged => 'intake',
            ComplianceCaseStatus::Investigating => 'investigation',
            ComplianceCaseStatus::ActionRequired => 'remediation',
            ComplianceCaseStatus::Resolved => 'resolution',
            ComplianceCaseStatus::Closed => 'terminal',
        };
    }
}
