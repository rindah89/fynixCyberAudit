<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrivacyActivityStatus: string implements HasColor, HasLabel
{
    case Draft = 'Draft';
    case AssessmentRequired = 'Assessment Required';
    case Active = 'Active';
    case Retired = 'Retired';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'info', self::AssessmentRequired => 'warning', self::Active => 'success', self::Retired => 'gray'
        };
    }
}
