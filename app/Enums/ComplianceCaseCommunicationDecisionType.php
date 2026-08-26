<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseCommunicationDecisionType: string implements HasLabel
{
    case Required = 'required';
    case Prepared = 'prepared';
    case Sent = 'sent';
    case Waived = 'waived';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Required => __('Required'),
            self::Prepared => __('Prepared'),
            self::Sent => __('Sent'),
            self::Waived => __('Waived'),
            self::Cancelled => __('Cancelled'),
        };
    }
}
