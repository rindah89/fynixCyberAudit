<?php

namespace App\RiskAssessor;

use App\Ai\EvidenceSearch;
use App\Enums\MitigationType;
use App\Enums\RiskStatus;
use App\Models\Risk;
use App\Models\RiskAssessment;
use App\Models\RiskAssessmentItem;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Support\Enterprise;
use Illuminate\Support\Collection;

class RiskAssessor
{
    public function __construct(
        private readonly AiService $ai,
        private readonly EvidenceSearch $evidence,
    ) {}

    public function evaluate(User $actor, RiskAssessmentItem $item): RiskAssessmentItem
    {
        $this->authorize($actor, $item->assessment);

        $hits = $this->evidence->search($item->name.' '.$item->description, 8);
        $result = $this->ai->chatJson(
            'You assess residual risk using this organisation\'s 1-5 likelihood and impact scales. Return JSON.',
            $this->prompt($item, $hits),
            ['residual_likelihood', 'residual_impact', 'treatment', 'justification', 'confidence'],
        );

        $content = $result['content'];
        $likelihood = $this->clampScore($content['residual_likelihood'] ?? 1);
        $impact = $this->clampScore($content['residual_impact'] ?? 1);

        $justification = trim((string) ($content['justification'] ?? ''));
        $item->update([
            'residual_likelihood' => $likelihood,
            'residual_impact' => $impact,
            'residual_risk' => $likelihood * $impact,
            'treatment' => (string) ($content['treatment'] ?? MitigationType::MITIGATE->value),
            'justification' => '[AI Assessment] '.$justification,
            'ai_meta' => [
                'source' => 'ai',
                'overridable' => true,
                'confidence' => (float) ($content['confidence'] ?? 0),
                'evidence' => $hits,
            ],
        ]);

        return $item->fresh();
    }

    /**
     * @param  list<int>  $itemIds
     * @return Collection<int, Risk>
     */
    public function promote(User $actor, RiskAssessment $assessment, array $itemIds): Collection
    {
        $this->authorize($actor, $assessment);

        $items = $assessment->items()->whereIn('id', $itemIds)->get();
        $risks = collect();

        foreach ($items as $item) {
            $payload = [
                'name' => $item->name,
                'description' => $item->description,
                'inherent_likelihood' => $item->inherent_likelihood ?? 1,
                'inherent_impact' => $item->inherent_impact ?? 1,
                'inherent_risk' => ($item->inherent_likelihood ?? 1) * ($item->inherent_impact ?? 1),
                'residual_likelihood' => $item->residual_likelihood ?? $item->inherent_likelihood ?? 1,
                'residual_impact' => $item->residual_impact ?? $item->inherent_impact ?? 1,
                'residual_risk' => $item->residual_risk
                    ?? (($item->residual_likelihood ?? 1) * ($item->residual_impact ?? 1)),
                'status' => RiskStatus::ASSESSED,
                'is_active' => true,
            ];

            if ($item->risk_id) {
                $risk = Risk::query()->findOrFail($item->risk_id);
                $risk->update($payload);
            } else {
                $payload['code'] = (string) Risk::next();
                $risk = Risk::query()->create($payload);
                $item->update(['risk_id' => $risk->id]);
            }

            $risks->push($risk->fresh());
        }

        return $risks;
    }

    private function authorize(User $actor, RiskAssessment $assessment): void
    {
        Enterprise::assertEnabled('risk_assessor');

        if ($assessment->isCollaborator($actor)) {
            return;
        }

        abort(403, 'Only assessment collaborators can do that.');
    }

    /** @param list<array<string, mixed>> $hits */
    private function prompt(RiskAssessmentItem $item, array $hits): string
    {
        $evidence = collect($hits)
            ->map(fn (array $hit) => ($hit['type'] ?? '').': '.($hit['title'] ?? ''))
            ->implode("\n");

        return implode("\n", [
            'Risk: '.$item->name,
            'Description: '.$item->description,
            'Inherent L/I: '.$item->inherent_likelihood.'/'.$item->inherent_impact,
            'Evidence:',
            $evidence,
            'Return residual_likelihood (1-5), residual_impact (1-5), treatment, justification, confidence (0-1).',
        ]);
    }

    private function clampScore(mixed $value): int
    {
        return max(1, min(5, (int) $value));
    }
}
