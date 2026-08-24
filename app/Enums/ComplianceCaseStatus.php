<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseStatus: string implements HasColor, HasLabel
{
    case New = 'New';
    case Triaged = 'Triaged';
    case Investigating = 'Investigating';
    case ActionRequired = 'Action Required';
    case Resolved = 'Resolved';
    case Closed = 'Closed';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info', self::Triaged, self::Investigating, self::ActionRequired => 'warning',
            self::Resolved, self::Closed => 'success',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::New => [self::Triaged],
            self::Triaged => [self::Investigating],
            self::Investigating => [self::ActionRequired, self::Resolved],
            self::ActionRequired => [self::Investigating, self::Resolved],
            self::Resolved => [self::Closed],
            self::Closed => [],
        };
    }
}
