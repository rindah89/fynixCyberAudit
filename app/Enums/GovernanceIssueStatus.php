<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum GovernanceIssueStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case InRemediation = 'in_remediation';
    case Verification = 'verification';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => __('Open'),
            self::InRemediation => __('In remediation'),
            self::Verification => __('Verification'),
            self::Closed => __('Closed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::InRemediation, self::Verification => 'warning',
            self::Closed => 'success',
        };
    }
}
