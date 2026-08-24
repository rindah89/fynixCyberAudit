<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyDueDiligenceDecision: string implements HasColor, HasLabel
{
    case Satisfactory = 'satisfactory';
    case Conditional = 'conditional';
    case Unsatisfactory = 'unsatisfactory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Satisfactory => __('Satisfactory'), self::Conditional => __('Conditional'), self::Unsatisfactory => __('Unsatisfactory'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Satisfactory => 'success', self::Conditional => 'warning', self::Unsatisfactory => 'danger',
        };
    }
}
