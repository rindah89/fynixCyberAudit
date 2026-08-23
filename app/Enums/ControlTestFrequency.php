<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum ControlTestFrequency: string implements HasLabel
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::OneTime => __('One time'), self::Monthly => __('Monthly'), self::Quarterly => __('Quarterly'),
            self::SemiAnnual => __('Semi-annual'), self::Annual => __('Annual'),
        };
    }

    public function nextRunAt(Carbon $executedAt): ?Carbon
    {
        return match ($this) {
            self::OneTime => null,
            self::Monthly => $executedAt->copy()->addMonthNoOverflow(),
            self::Quarterly => $executedAt->copy()->addMonthsNoOverflow(3),
            self::SemiAnnual => $executedAt->copy()->addMonthsNoOverflow(6),
            self::Annual => $executedAt->copy()->addYearNoOverflow(),
        };
    }
}
