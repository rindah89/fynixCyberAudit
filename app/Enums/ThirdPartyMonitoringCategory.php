<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ThirdPartyMonitoringCategory: string implements HasLabel
{
    case ServiceLevel = 'service_level';
    case Availability = 'availability';
    case Security = 'security';
    case Privacy = 'privacy';
    case Compliance = 'compliance';
    case Financial = 'financial';
    case Concentration = 'concentration';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::ServiceLevel => __('Service level'), self::Availability => __('Availability'), self::Security => __('Security'),
            self::Privacy => __('Privacy'), self::Compliance => __('Compliance'), self::Financial => __('Financial'),
            self::Concentration => __('Concentration'), self::Other => __('Other'),
        };
    }
}
