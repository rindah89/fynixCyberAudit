<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyCollaborationReminderType: string implements HasColor, HasLabel
{
    case DueSoon = 'due_soon';
    case Overdue = 'overdue';

    public function getLabel(): string
    {
        return match ($this) {
            self::DueSoon => __('Due Soon'),
            self::Overdue => __('Overdue'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::DueSoon => 'warning',
            self::Overdue => 'danger',
        };
    }
}
