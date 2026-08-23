<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum RecoveryExerciseOutcome: string implements HasLabel
{
    case Passed = 'passed';
    case Partial = 'partial';
    case Failed = 'failed';

    public function getLabel(): ?string
    {
        return __(ucfirst($this->value));
    }
}
