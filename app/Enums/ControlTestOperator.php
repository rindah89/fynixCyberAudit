<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ControlTestOperator: string implements HasLabel
{
    case Equals = 'equals';
    case NotEquals = 'not_equals';
    case GreaterThan = 'greater_than';
    case GreaterThanOrEqual = 'greater_than_or_equal';
    case LessThan = 'less_than';
    case LessThanOrEqual = 'less_than_or_equal';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Equals => __('Equals'),
            self::NotEquals => __('Does not equal'),
            self::GreaterThan => __('Greater than'),
            self::GreaterThanOrEqual => __('Greater than or equal'),
            self::LessThan => __('Less than'),
            self::LessThanOrEqual => __('Less than or equal'),
        };
    }
}
