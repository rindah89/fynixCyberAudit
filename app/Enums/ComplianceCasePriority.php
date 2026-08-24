<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCasePriority: string implements HasColor, HasLabel
{
    case Low = 'Low';
    case Medium = 'Medium';
    case High = 'High';
    case Critical = 'Critical';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Critical => 'danger', self::High => 'warning', self::Medium => 'info', self::Low => 'gray',
        };
    }
}
