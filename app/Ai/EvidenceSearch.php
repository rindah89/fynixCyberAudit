<?php

namespace App\Ai;

use App\Models\Control;
use App\Models\Implementation;
use App\Models\Policy;
use App\Models\Standard;
use Illuminate\Support\Str;

class EvidenceSearch
{
    /**
     * @return list<array{type: string, id: int, title: string, excerpt: string, score: float}>
     */
    public function search(string $query, int $limit = 10): array
    {
        $terms = array_values(array_filter(preg_split('/\s+/', trim($query)) ?: []));
        if ($terms === []) {
            return [];
        }

        $hits = [];

        foreach (Policy::query()->get(['id', 'name', 'body', 'purpose']) as $policy) {
            $haystack = $policy->name.' '.$policy->purpose.' '.$policy->body;
            if ($this->matches($haystack, $terms)) {
                $hits[] = $this->hit('policy', (int) $policy->id, (string) $policy->name, (string) $policy->body);
            }
        }

        foreach (Implementation::query()->get(['id', 'title', 'details']) as $implementation) {
            $haystack = $implementation->title.' '.$implementation->details;
            if ($this->matches($haystack, $terms)) {
                $hits[] = $this->hit('implementation', (int) $implementation->id, (string) $implementation->title, (string) $implementation->details);
            }
        }

        foreach (Control::query()->get(['id', 'title', 'description']) as $control) {
            $haystack = $control->title.' '.$control->description;
            if ($this->matches($haystack, $terms)) {
                $hits[] = $this->hit('control', (int) $control->id, (string) $control->title, (string) $control->description);
            }
        }

        foreach (Standard::query()->get(['id', 'name', 'description']) as $standard) {
            $haystack = $standard->name.' '.$standard->description;
            if ($this->matches($haystack, $terms)) {
                $hits[] = $this->hit('standard', (int) $standard->id, (string) $standard->name, (string) $standard->description);
            }
        }

        return array_slice($hits, 0, $limit);
    }

    /** @param list<string> $terms */
    private function matches(string $haystack, array $terms): bool
    {
        $haystack = mb_strtolower(strip_tags($haystack));
        foreach ($terms as $term) {
            if ($term !== '' && str_contains($haystack, mb_strtolower($term))) {
                return true;
            }
        }

        return false;
    }

    /** @return array{type: string, id: int, title: string, excerpt: string, score: float} */
    private function hit(string $type, int $id, string $title, string $body): array
    {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'excerpt' => Str::limit(trim(strip_tags($body)), 240),
            'score' => 1.0,
        ];
    }
}
