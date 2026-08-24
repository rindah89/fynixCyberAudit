<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RegulatoryApplicability: string implements HasColor, HasLabel
{
    case Applicable = 'applicable';
    case NotApplicable = 'not_applicable';
    case UnderReview = 'under_review';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Applicable => 'success', self::UnderReview => 'warning', self::NotApplicable => 'gray',
        };
    }
}
