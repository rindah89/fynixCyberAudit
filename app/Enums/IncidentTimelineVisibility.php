<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentTimelineVisibility: string implements HasColor, HasLabel
{
    case Internal = 'Internal';
    case Auditor = 'Auditor';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Internal => __('enums.incident_timeline_visibility.internal'),
            self::Auditor => __('enums.incident_timeline_visibility.auditor'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Internal => 'warning', self::Auditor => 'info'
        };
    }
}
