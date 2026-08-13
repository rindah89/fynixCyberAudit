<?php

namespace App\Surveyor;

use App\Ai\EvidenceSearch;
use App\Models\AiJob;
use App\Models\User;
use App\Services\Ai\AiService;
use App\Support\Enterprise;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class Surveyor
{
    public function __construct(
        private readonly AiService $ai,
        private readonly EvidenceSearch $evidence,
    ) {}

    /**
     * @return array{
     *     verdict: string,
     *     confidence: string,
     *     coverage: string,
     *     rationale: string,
     *     evidence: list<array<string, mixed>>,
     *     needs_human_review: bool
     * }
     */
    public function answer(User $actor, string $question): array
    {
        $this->authorize($actor);

        $hits = $this->evidence->search($question, 8);
        $result = $this->ai->chatJson(
            'You answer inbound security questionnaires using only the supplied evidence. Return JSON.',
            $this->prompt($question, $hits),
            ['verdict', 'confidence', 'coverage', 'rationale', 'needs_human_review'],
        );

        $content = $result['content'];
        $confidence = strtoupper((string) ($content['confidence'] ?? 'LOW'));
        if (! in_array($confidence, ['HIGH', 'MEDIUM', 'LOW'], true)) {
            $confidence = 'LOW';
        }

        $verdict = $this->normalizeVerdict($content['verdict'] ?? '');
        $needsReview = (bool) ($content['needs_human_review'] ?? false) || $confidence === 'LOW';

        return [
            'verdict' => $verdict,
            'confidence' => $confidence,
            'coverage' => (string) ($content['coverage'] ?? ''),
            'rationale' => (string) ($content['rationale'] ?? ''),
            'evidence' => $hits,
            'needs_human_review' => $needsReview,
        ];
    }

    public function startBatch(User $actor, string $csv, string $filename = 'questions.csv'): AiJob
    {
        $this->authorize($actor);

        $rows = $this->parseCsv($csv);
        if ($rows === []) {
            throw new InvalidArgumentException('CSV has no data rows.');
        }

        $headers = array_keys($rows[0]);
        if (! in_array('question', $headers, true)) {
            throw new InvalidArgumentException('CSV must include a question column.');
        }

        $max = (int) config('enterprise.surveyor.max_batch_questions', 100);
        if (count($rows) > $max) {
            throw new InvalidArgumentException('CSV exceeds the maximum number of questions.');
        }

        $relative = 'surveyor/'.uniqid('batch_', true).'.csv';
        $headerLine = fopen('php://temp', 'r+');
        fputcsv($headerLine, array_merge($headers, ['verdict', 'confidence', 'coverage', 'rationale', 'evidence', 'needs_human_review']));
        rewind($headerLine);
        Storage::disk('local')->put($relative, stream_get_contents($headerLine) ?: '');
        fclose($headerLine);

        return AiJob::create([
            'type' => 'surveyor_batch',
            'status' => 'pending',
            'total' => count($rows),
            'processed' => 0,
            'failed' => 0,
            'result_path' => $relative,
            'meta' => [
                'filename' => $filename,
                'headers' => $headers,
                'rows' => $rows,
                'next_index' => 0,
            ],
            'created_by' => $actor->id,
        ]);
    }

    public function processNext(AiJob $job): bool
    {
        $job->refresh();

        if ($job->isCancelled() || in_array($job->status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }

        $meta = $job->meta ?? [];
        $rows = $meta['rows'] ?? [];
        $index = (int) ($meta['next_index'] ?? 0);

        if ($index >= count($rows)) {
            $job->update(['status' => 'completed']);

            return false;
        }

        $job->update(['status' => 'running']);
        $row = $rows[$index];
        $actor = $job->creator;
        $answer = $this->answer($actor, (string) ($row['question'] ?? ''));

        $headers = $meta['headers'] ?? array_keys($row);
        $ordered = [];
        foreach ($headers as $header) {
            $ordered[] = $row[$header] ?? '';
        }
        $evidence = collect($answer['evidence'])
            ->map(fn (array $hit) => ($hit['type'] ?? '').':'.($hit['title'] ?? ''))
            ->implode('; ');
        $line = fopen('php://temp', 'r+');
        fputcsv($line, array_merge($ordered, [
            $answer['verdict'],
            $answer['confidence'],
            $answer['coverage'],
            $answer['rationale'],
            $evidence,
            $answer['needs_human_review'] ? 'yes' : 'no',
        ]));
        rewind($line);
        Storage::disk('local')->append($job->result_path, rtrim((string) stream_get_contents($line), "\n"));
        fclose($line);

        $meta['next_index'] = $index + 1;
        $job->update([
            'meta' => $meta,
            'processed' => $index + 1,
            'status' => ($index + 1) >= count($rows) ? 'completed' : 'running',
        ]);

        return ($index + 1) < count($rows);
    }

    public function cancel(User $actor, AiJob $job): void
    {
        $this->authorize($actor);

        if ((int) $job->created_by !== (int) $actor->id && ! $actor->isSuperAdmin()) {
            abort(403, 'You cannot cancel this batch.');
        }

        $job->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);
    }

    private function authorize(User $actor): void
    {
        Enterprise::assertEnabled('surveyor');

        if ($actor->isSuperAdmin() || $actor->can('Manage Surveyor')) {
            return;
        }

        abort(403, 'You cannot use Surveyor.');
    }

    /** @param list<array<string, mixed>> $hits */
    private function prompt(string $question, array $hits): string
    {
        $evidence = collect($hits)
            ->map(fn (array $hit) => ($hit['type'] ?? '').': '.($hit['title'] ?? '').' — '.($hit['excerpt'] ?? ''))
            ->implode("\n");

        return "Question: {$question}\nEvidence:\n{$evidence}\nReturn verdict (Meets|Partially meets|Does not meet), confidence, coverage, rationale, needs_human_review.";
    }

    private function normalizeVerdict(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match (true) {
            str_contains($normalized, 'partial') => 'Partially meets',
            str_contains($normalized, 'does not') || str_contains($normalized, 'not meet') => 'Does not meet',
            default => 'Meets',
        };
    }

    /** @return list<array<string, string>> */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $headers = fgetcsv($handle);
        if (! is_array($headers) || $headers === []) {
            fclose($handle);

            return [];
        }

        $headers = array_map(fn ($h) => strtolower(trim((string) $h)), $headers);
        $rows = [];
        while (($data = fgetcsv($handle)) !== false) {
            if ($data === [null] || $data === false) {
                continue;
            }
            $row = [];
            foreach ($headers as $i => $header) {
                $row[$header] = (string) ($data[$i] ?? '');
            }
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
