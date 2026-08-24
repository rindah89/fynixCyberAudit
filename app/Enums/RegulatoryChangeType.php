<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RegulatoryChangeType: string implements HasLabel
{
    case NewRequirement = 'new_requirement';
    case Amendment = 'amendment';
    case Guidance = 'guidance';
    case Repeal = 'repeal';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}
