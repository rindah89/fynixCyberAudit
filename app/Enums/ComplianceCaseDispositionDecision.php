<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseDispositionDecision: string implements HasLabel
{
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Deferred = 'deferred';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => __('Approved'),
            self::Rejected => __('Rejected'),
            self::Deferred => __('Deferred'),
        };
    }
}
