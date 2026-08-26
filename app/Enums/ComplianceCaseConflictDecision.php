<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseConflictDecision: string implements HasLabel
{
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Confirmed => __('Confirmed'),
            self::Rejected => __('Rejected'),
        };
    }
}
