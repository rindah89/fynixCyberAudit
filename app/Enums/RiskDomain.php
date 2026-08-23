<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RiskDomain: string implements HasColor, HasLabel
{
    case Enterprise = 'enterprise';
    case Operational = 'operational';
    case Technology = 'technology';
    case ThirdParty = 'third_party';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Enterprise => 'Enterprise',
            self::Operational => 'Operational',
            self::Technology => 'Technology',
            self::ThirdParty => 'Third-party',
        };
    }

    public function getColor(): string|array|null
    {
        return 'gray';
    }
}
