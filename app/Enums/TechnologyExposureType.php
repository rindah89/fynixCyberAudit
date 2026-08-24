<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TechnologyExposureType: string implements HasLabel
{
    case Vulnerability = 'vulnerability';
    case ThreatScenario = 'threat_scenario';
    case Misconfiguration = 'misconfiguration';
    case UnsupportedTechnology = 'unsupported_technology';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vulnerability => __('Vulnerability'),
            self::ThreatScenario => __('Threat scenario'),
            self::Misconfiguration => __('Misconfiguration'),
            self::UnsupportedTechnology => __('Unsupported technology'),
            self::Other => __('Other'),
        };
    }
}
