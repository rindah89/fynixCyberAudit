<?php

namespace App\AiAudit;

use App\Ai\EvidenceSearch;
use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Models\Audit;
use App\Models\AuditItem;
use App\Models\Control;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Support\Enterprise;
use Illuminate\Support\Str;

class PerformAiAudit
{
    public function __construct(
        private readonly AiService $ai,
        private readonly EvidenceSearch $evidence,
    ) {}

    public function __invoke(User $actor, Audit $audit): void
    {
        Enterprise::assertEnabled('ai_audit');
        $this->authorize($actor, $audit);

        if ($audit->status !== WorkflowStatus::INPROGRESS) {
            abort(422, 'AI Audit can only run on an in-progress audit.');
        }

        foreach ($audit->auditItems as $item) {
            if (! $this->isControlItem($item)) {
                continue;
            }

            $this->assess($item);
        }
    }

    private function authorize(User $actor, Audit $audit): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ((int) $audit->manager_id === (int) $actor->id) {
            return;
        }

        abort(403, 'Only the audit manager can perform an AI audit.');
    }

    private function isControlItem(AuditItem $item): bool
    {
        return $item->auditable_type === Control::class
            || is_a((string) $item->auditable_type, Control::class, true);
    }

    private function assess(AuditItem $item): void
    {
        $control = $item->auditable;
        $query = trim(($control->title ?? '').' '.($control->description ?? ''));
        $hits = $this->evidence->search($query !== '' ? $query : 'control', 8);

        $result = $this->ai->chatJson(
            'You assess GRC audit items. Return only JSON.',
            $this->userPrompt($item, $hits),
            ['effectiveness', 'applicability', 'confidence', 'needs_human_review', 'notes'],
        );

        $content = $result['content'];
        $confidence = strtoupper((string) ($content['confidence'] ?? 'LOW'));
        if (! in_array($confidence, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $confidence = 'LOW';
        }

        $notes = trim((string) ($content['notes'] ?? ''));
        $needsReview = (bool) ($content['needs_human_review'] ?? false) || $confidence === 'LOW';
        if ($needsReview) {
            $notes = trim($notes.' Needs human review.');
        }
        $item->update([
            'effectiveness' => $this->mapEffectiveness($content['effectiveness'] ?? null),
            'applicability' => $this->mapApplicability($content['applicability'] ?? null),
            'auditor_notes' => '[AI Assessment - Confidence: '.$confidence.'] '.$notes,
        ]);
    }

    /** @param list<array<string, mixed>> $hits */
    private function userPrompt(AuditItem $item, array $hits): string
    {
        $control = $item->auditable;
        $evidence = collect($hits)
            ->map(fn (array $hit) => ($hit['type'] ?? '').': '.($hit['title'] ?? '').' — '.($hit['excerpt'] ?? ''))
            ->implode("\n");

        return implode("\n", [
            'Control: '.($control->title ?? ''),
            'Description: '.($control->description ?? ''),
            'Evidence:',
            $evidence,
            'Return effectiveness, applicability, confidence (HIGH|MEDIUM|LOW), needs_human_review, notes.',
        ]);
    }

    private function mapEffectiveness(mixed $value): Effectiveness
    {
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            str_contains($normalized, 'partial') => Effectiveness::PARTIAL,
            str_contains($normalized, 'not') || str_contains($normalized, 'ineffective') => Effectiveness::INEFFECTIVE,
            str_contains($normalized, 'effective') || str_contains($normalized, 'implemented') => Effectiveness::EFFECTIVE,
            default => Effectiveness::UNKNOWN,
        };
    }

    private function mapApplicability(mixed $value): Applicability
    {
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            str_contains($normalized, 'not') => Applicability::NOTAPPLICABLE,
            str_contains($normalized, 'partial') => Applicability::PARTIALLYAPPLICABLE,
            str_contains($normalized, 'applicable') => Applicability::APPLICABLE,
            default => Applicability::UNKNOWN,
        };
    }
}
