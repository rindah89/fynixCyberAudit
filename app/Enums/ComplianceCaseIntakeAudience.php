<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseIntakeAudience: string implements HasColor, HasLabel
{
    case Reporter = 'Reporter';
    case Internal = 'Internal';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reporter => 'info', self::Internal => 'warning',
        };
    }
}
