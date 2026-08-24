<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentPhase: string implements HasColor, HasLabel
{
    case Identification = 'Identification';
    case Containment = 'Containment';
    case Eradication = 'Eradication';
    case Recovery = 'Recovery';
    case LessonsLearned = 'Lessons Learned';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Identification => __('enums.incident_phase.identification'),
            self::Containment => __('enums.incident_phase.containment'),
            self::Eradication => __('enums.incident_phase.eradication'),
            self::Recovery => __('enums.incident_phase.recovery'),
            self::LessonsLearned => __('enums.incident_phase.lessons_learned'),
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Identification => 1,
            self::Containment => 2,
            self::Eradication => 3,
            self::Recovery => 4,
            self::LessonsLearned => 5,
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Identification => 'info',
            self::Containment, self::Eradication => 'warning',
            self::Recovery => 'success',
            self::LessonsLearned => 'gray',
        };
    }
}
