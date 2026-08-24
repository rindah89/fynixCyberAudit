<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseCategory: string implements HasLabel
{
    case Conduct = 'Conduct';
    case Fraud = 'Fraud';
    case Regulatory = 'Regulatory';
    case PolicyViolation = 'Policy Violation';
    case Privacy = 'Privacy';
    case ConflictOfInterest = 'Conflict of Interest';
    case Retaliation = 'Retaliation';
    case Other = 'Other';

    public function getLabel(): string
    {
        return __($this->value);
    }
}
