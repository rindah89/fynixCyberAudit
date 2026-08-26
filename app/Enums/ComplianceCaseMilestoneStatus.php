<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseMilestoneStatus: string implements HasLabel
{
    case Open = 'open';
    case Completed = 'completed';
    case Waived = 'waived';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::Completed => __('Completed'),
            self::Waived => __('Waived'),
        };
    }
}
