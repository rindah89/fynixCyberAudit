<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Carbon;

enum PolicyObligationFrequency: string implements HasLabel
{
    case OneTime = 'one_time';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case SemiAnnual = 'semi_annual';
    case Annual = 'annual';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::OneTime => 'One time',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::SemiAnnual => 'Semi-annual',
            self::Annual => 'Annual',
        };
    }

    public function nextDueAt(Carbon $attestedAt): ?Carbon
    {
        return match ($this) {
            self::OneTime => null,
            self::Monthly => $attestedAt->copy()->addMonthNoOverflow(),
            self::Quarterly => $attestedAt->copy()->addMonthsNoOverflow(3),
            self::SemiAnnual => $attestedAt->copy()->addMonthsNoOverflow(6),
            self::Annual => $attestedAt->copy()->addYearNoOverflow(),
        };
    }
}
