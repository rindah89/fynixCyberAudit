<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentTaskStatus: string implements HasColor, HasLabel
{
    case Open = 'Open';
    case InProgress = 'In Progress';
    case Blocked = 'Blocked';
    case Completed = 'Completed';
    case Cancelled = 'Cancelled';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'info', self::InProgress => 'warning', self::Blocked => 'danger',
            self::Completed => 'success', self::Cancelled => 'gray',
        };
    }

    /** @return array<int, self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Open => [self::InProgress, self::Cancelled],
            self::InProgress => [self::Blocked, self::Completed, self::Cancelled],
            self::Blocked => [self::InProgress, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }
}
