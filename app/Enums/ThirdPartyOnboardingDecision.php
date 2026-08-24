<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyOnboardingDecision: string implements HasColor, HasLabel
{
    case Ready = 'ready';
    case ReadyWithConditions = 'ready_with_conditions';
    case NotReady = 'not_ready';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ready => __('Ready'), self::ReadyWithConditions => __('Ready with Conditions'), self::NotReady => __('Not Ready')
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Ready => 'success', self::ReadyWithConditions => 'warning', self::NotReady => 'danger'
        };
    }
}
