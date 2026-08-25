<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseInvestigationProcedureReviewDecision: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case ReworkRequired = 'rework_required';

    public function getLabel(): string
    {
        return match ($this) {
            self::Approved => __('Approved'),
            self::ReworkRequired => __('Rework Required'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Approved => 'success',
            self::ReworkRequired => 'warning',
        };
    }
}
