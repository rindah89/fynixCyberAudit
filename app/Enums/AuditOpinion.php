<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditOpinion: string implements HasColor, HasLabel
{
    case Satisfactory = 'satisfactory';
    case NeedsImprovement = 'needs_improvement';
    case Unsatisfactory = 'unsatisfactory';

    public function getLabel(): string
    {
        return match ($this) {
            self::Satisfactory => __('Satisfactory'),
            self::NeedsImprovement => __('Needs improvement'),
            self::Unsatisfactory => __('Unsatisfactory'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Satisfactory => 'success',
            self::NeedsImprovement => 'warning',
            self::Unsatisfactory => 'danger',
        };
    }
}
