<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditProcedureOutcome: string implements HasColor, HasLabel
{
    case Effective = 'effective';
    case NeedsImprovement = 'needs_improvement';
    case Ineffective = 'ineffective';
    case NotApplicable = 'not_applicable';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Effective => 'success',
            self::NeedsImprovement => 'warning',
            self::Ineffective => 'danger',
            self::NotApplicable => 'gray',
        };
    }
}
