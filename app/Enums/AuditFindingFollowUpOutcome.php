<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditFindingFollowUpOutcome: string implements HasColor, HasLabel
{
    case Effective = 'effective';
    case PartiallyEffective = 'partially_effective';
    case Ineffective = 'ineffective';

    public function getLabel(): string
    {
        return match ($this) {
            self::Effective => __('Effective'),
            self::PartiallyEffective => __('Partially effective'),
            self::Ineffective => __('Ineffective'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Effective => 'success',
            self::PartiallyEffective => 'warning',
            self::Ineffective => 'danger',
        };
    }
}
