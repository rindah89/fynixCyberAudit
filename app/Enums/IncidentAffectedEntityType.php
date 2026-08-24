<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IncidentAffectedEntityType: string implements HasLabel
{
    case Asset = 'Asset';
    case Application = 'Application';
    case Vendor = 'Vendor';
    case Control = 'Control';
    case Risk = 'Risk';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Asset => __('enums.incident_affected_entity_type.asset'),
            self::Application => __('enums.incident_affected_entity_type.application'),
            self::Vendor => __('enums.incident_affected_entity_type.vendor'),
            self::Control => __('enums.incident_affected_entity_type.control'),
            self::Risk => __('enums.incident_affected_entity_type.risk'),
        };
    }
}
