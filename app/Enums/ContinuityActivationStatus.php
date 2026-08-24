<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContinuityActivationStatus: string implements HasColor, HasLabel
{
    case Activated = 'activated';
    case Recovering = 'recovering';
    case Restored = 'restored';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Activated => __('Activated'), self::Recovering => __('Recovering'),
            self::Restored => __('Restored'), self::Closed => __('Closed'), self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Activated, self::Recovering => 'warning', self::Restored => 'info',
            self::Closed => 'success', self::Cancelled => 'gray',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Activated => [self::Recovering, self::Cancelled],
            self::Recovering => [self::Restored, self::Cancelled],
            self::Restored => [self::Closed],
            self::Closed, self::Cancelled => [],
        };
    }
}
