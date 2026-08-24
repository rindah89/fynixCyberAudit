<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum RiskIndicatorFrequency: string implements HasLabel
{
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    public function getLabel(): string
    {
        return match ($this) {
            self::Weekly => __('Weekly'),
            self::Monthly => __('Monthly'),
            self::Quarterly => __('Quarterly'),
            self::Yearly => __('Yearly'),
        };
    }

    public function nextDueAt(Carbon $observedAt): Carbon
    {
        return match ($this) {
            self::Weekly => $observedAt->copy()->addWeek(),
            self::Monthly => $observedAt->copy()->addMonthNoOverflow(),
            self::Quarterly => $observedAt->copy()->addMonthsNoOverflow(3),
            self::Yearly => $observedAt->copy()->addYearNoOverflow(),
        };
    }
}
