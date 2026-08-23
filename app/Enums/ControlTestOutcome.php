<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ControlTestOutcome: string implements HasLabel
{
    case Passed = 'passed';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return __(ucfirst($this->value));
    }
}
