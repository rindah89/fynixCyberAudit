<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyCollaborationEscalationStatus: string implements HasColor, HasLabel
{
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Acknowledged => __('Acknowledged'),
            self::Resolved => __('Resolved'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Acknowledged => 'warning',
            self::Resolved => 'success',
        };
    }
}
