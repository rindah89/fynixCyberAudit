<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ContinuityRecoveryOutcome: string implements HasColor, HasLabel
{
    case Met = 'met';
    case Partial = 'partial';
    case Missed = 'missed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Met => __('Met'), self::Partial => __('Partially Met'), self::Missed => __('Missed')
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Met => 'success', self::Partial => 'warning', self::Missed => 'danger'
        };
    }
}
