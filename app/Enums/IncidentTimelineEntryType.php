<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentTimelineEntryType: string implements HasColor, HasLabel
{
    case Observation = 'Observation';
    case Action = 'Action';
    case Decision = 'Decision';
    case Communication = 'Communication';

    public function getLabel(): ?string
    {
        return __('enums.incident_timeline_entry_type.'.strtolower($this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Observation => 'info', self::Action => 'warning', self::Decision => 'success', self::Communication => 'gray',
        };
    }
}
