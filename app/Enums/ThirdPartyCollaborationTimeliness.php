<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyCollaborationTimeliness: string implements HasColor, HasLabel
{
    case OnTime = 'on_time';
    case Late = 'late';

    public function getLabel(): string
    {
        return match ($this) {
            self::OnTime => __('On time'),
            self::Late => __('Late'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::OnTime => 'success',
            self::Late => 'danger',
        };
    }
}
