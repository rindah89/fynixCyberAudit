<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RiskLevel: int implements HasLabel
{
    case VeryLow = 1;
    case Low = 2;
    case Moderate = 3;
    case High = 4;
    case VeryHigh = 5;

    public function getLabel(): string
    {
        return match ($this) {
            self::VeryLow => 'Very Low',
            self::Low => 'Low',
            self::Moderate => 'Moderate',
            self::High => 'High',
            self::VeryHigh => 'Very High',
        };
    }

    /**
     * Get options array for form fields and filters (string keys for compatibility).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [(string) $case->value => $case->getLabel()])
            ->toArray();
    }

    /**
     * Get the risk level enum case from a score (1-25).
     *
     * Score ranges:
     * - 1-4:   Very Low
     * - 5-8:   Low
     * - 9-12:  Moderate
     * - 13-17: High
     * - 18-25: Very High
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= 18 => self::VeryHigh,
            $score >= 13 => self::High,
            $score >= 9 => self::Moderate,
            $score >= 5 => self::Low,
            default => self::VeryLow,
        };
    }

    /**
     * Get the risk level enum case from likelihood and impact values.
     */
    public static function fromLikelihoodAndImpact(int $likelihood, int $impact): self
    {
        return self::fromScore($likelihood * $impact);
    }

    /**
     * Format risk as "Label (score)" for display.
     */
    public static function formatRisk(int $likelihood, int $impact): string
    {
        $score = $likelihood * $impact;
        $label = self::fromScore($score)->getLabel();

        return "{$label} ({$score})";
    }

    /**
     * Get the Filament color name for a risk based on likelihood and impact.
     * Used by Filament badge columns for proper light/dark mode support.
     */
    public static function getFilamentColor(int $likelihood, int $impact): string
    {
        $score = $likelihood * $impact;

        return match (true) {
            $score >= 18 => 'danger',    // Very High risk
            $score >= 13 => 'warning',   // High risk
            $score >= 9 => 'info',       // Moderate risk
            $score >= 5 => 'primary',    // Low risk
            default => 'success',        // Very Low risk
        };
    }

    /**
     * CSS class for a risk heatmap cell. Soft = empty cell wash; strong = occupied.
     */
    public static function getColor(int $likelihood, int $impact, int $weight = 200): string
    {
        $score = $likelihood * $impact;
        $tone = $weight >= 500 ? 'strong' : 'soft';

        $level = match (true) {
            $score >= 18 => 'red',
            $score >= 13 => 'amber',
            $score >= 9 => 'yellow',
            $score >= 5 => 'blue',
            default => 'green',
        };

        return "risk-cell-{$level}-{$tone}";
    }

    /**
     * @return array{0: string, 1: string} [background, text]
     */
    public static function getHeatmapHex(int $likelihood, int $impact, int $weight = 200): array
    {
        return match (self::getColor($likelihood, $impact, $weight)) {
            'risk-cell-red-strong' => ['#d13817', '#ffffff'],
            'risk-cell-amber-strong' => ['#b96a00', '#ffffff'],
            'risk-cell-yellow-strong' => ['#ffd9a0', '#0a0a0a'],
            'risk-cell-blue-strong' => ['#2563eb', '#ffffff'],
            'risk-cell-green-strong' => ['#17a94c', '#ffffff'],
            'risk-cell-red-soft' => ['#fdece7', '#d13817'],
            'risk-cell-amber-soft' => ['#fff4e0', '#b96a00'],
            'risk-cell-yellow-soft' => ['#fff8e6', '#b96a00'],
            'risk-cell-blue-soft' => ['#ebf1fe', '#2563eb'],
            'risk-cell-green-soft' => ['#e9fef0', '#17a94c'],
            default => ['#efefed', '#8a8a88'],
        };
    }
}
