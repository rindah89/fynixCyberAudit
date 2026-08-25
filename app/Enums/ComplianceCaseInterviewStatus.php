<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseInterviewStatus: string implements HasColor, HasLabel
{
    case Scheduled = 'scheduled';
    case Conducted = 'conducted';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Scheduled => __('Scheduled'),
            self::Conducted => __('Conducted'),
            self::Cancelled => __('Cancelled'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Scheduled => 'warning',
            self::Conducted => 'success',
            self::Cancelled => 'gray',
        };
    }
}
