<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PolicyAttestationOutcome: string implements HasColor, HasLabel
{
    case Compliant = 'compliant';
    case NonCompliant = 'non_compliant';
    case NotApplicable = 'not_applicable';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Compliant => 'Compliant',
            self::NonCompliant => 'Non-compliant',
            self::NotApplicable => 'Not applicable',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Compliant => 'success',
            self::NonCompliant => 'danger',
            self::NotApplicable => 'gray',
        };
    }
}
