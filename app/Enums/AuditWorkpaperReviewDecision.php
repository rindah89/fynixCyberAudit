<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditWorkpaperReviewDecision: string implements HasColor, HasLabel
{
    case Approved = 'approved';
    case ReworkRequired = 'rework_required';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }

    public function getColor(): string
    {
        return $this === self::Approved ? 'success' : 'warning';
    }
}
