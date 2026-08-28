<?php

namespace App\Console\Commands;

use App\Filament\Pages\DataGovernanceOversight;
use App\Models\User;
use App\Notifications\DropdownNotification;
use App\Suite\GovernanceOversightService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class MonitorGovernanceOversight extends Command
{
    protected $signature = 'fynix:monitor-governance {--notify : Notify users with governance oversight permission}';

    protected $description = 'Check suite governance freshness and exceptions, and alert assurance users when state changes';

    public function handle(GovernanceOversightService $oversight): int
    {
        $report = $oversight->report(false);
        $summary = collect($report['sources'])->map(fn (array $source, string $name): string => sprintf(
            '%s:%s/%s/%d-open/%d-waived/%d-privacy-overdue/%d-reviews-pending/%d-invalid-reviews/%s-restore/%s-processors',
            $name,
            $source['binding'],
            $source['freshness'],
            $source['open_exceptions'],
            $source['waived_exceptions'],
            $source['operability']['overdue_privacy_requests'],
            $source['operability']['pending_processor_reviews']
                + $source['operability']['pending_privacy_reviews']
                + $source['operability']['pending_recovery_reviews']
                + $source['operability']['pending_disposition_reviews'],
            $source['operability']['invalid_or_tampered_reviews'],
            $source['operability']['current_approved_restore_evidence'] ? 'current' : 'missing-or-stale',
            $source['operability']['processor_register_certified'] ? 'certified' : 'uncertified',
        ))->values()->implode(', ');

        $this->line(json_encode([
            'status' => $report['status'],
            'generated_at' => $report['generated_at'],
            'summary' => $summary,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        if ($this->option('notify')) {
            $this->notifyOnChange($report['status'], $summary);
        }

        return $report['status'] === 'attention_required' ? self::FAILURE : self::SUCCESS;
    }

    private function notifyOnChange(string $status, string $summary): void
    {
        $fingerprint = hash('sha256', $status.'|'.$summary);
        $cacheKey = 'suite-governance-monitor:last-notified';
        if (Cache::get($cacheKey) === $fingerprint) {
            return;
        }

        $users = User::permission('View Governance Oversight')->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new DropdownNotification(
                title: $status === 'conformant' ? 'Suite data governance is conformant' : 'Suite data governance requires review',
                body: $summary,
                icon: $status === 'conformant' ? 'heroicon-o-shield-check' : 'heroicon-o-exclamation-triangle',
                color: $status === 'attention_required' ? 'danger' : ($status === 'conformant_with_waivers' ? 'warning' : 'success'),
                actionUrl: DataGovernanceOversight::getUrl(),
                actionLabel: 'Review governance',
            ));
        }

        Cache::put($cacheKey, $fingerprint, now()->addDay());
    }
}
