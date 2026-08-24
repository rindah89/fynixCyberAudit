<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyCollaborationStatus: string implements HasColor, HasLabel
{
    case Requested = 'requested';
    case Responded = 'responded';
    case FollowUp = 'follow_up';
    case Accepted = 'accepted';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Requested, self::FollowUp => 'warning', self::Responded => 'info', self::Accepted => 'success'
        };
    }
}
