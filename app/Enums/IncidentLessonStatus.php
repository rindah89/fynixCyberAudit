<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentLessonStatus: string implements HasColor, HasLabel
{
    case Proposed = 'Proposed';
    case InProgress = 'In Progress';
    case Implemented = 'Implemented';
    case ClosedWithoutAction = 'Closed Without Action';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'info', self::InProgress => 'warning',
            self::Implemented => 'success', self::ClosedWithoutAction => 'gray',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Proposed => [self::InProgress, self::ClosedWithoutAction],
            self::InProgress => [self::Implemented, self::ClosedWithoutAction],
            self::Implemented, self::ClosedWithoutAction => [],
        };
    }
}
