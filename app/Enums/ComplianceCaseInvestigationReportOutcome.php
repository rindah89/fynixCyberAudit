<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseInvestigationReportOutcome: string implements HasColor, HasLabel
{
    case Substantiated = 'substantiated';
    case PartiallySubstantiated = 'partially_substantiated';
    case Unsubstantiated = 'unsubstantiated';
    case Inconclusive = 'inconclusive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Substantiated => __('Substantiated'),
            self::PartiallySubstantiated => __('Partially Substantiated'),
            self::Unsubstantiated => __('Unsubstantiated'),
            self::Inconclusive => __('Inconclusive'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Substantiated => 'danger', self::PartiallySubstantiated => 'warning',
            self::Unsubstantiated => 'success', self::Inconclusive => 'gray',
        };
    }
}
