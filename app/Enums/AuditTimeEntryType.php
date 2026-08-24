<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AuditTimeEntryType: string implements HasColor, HasLabel
{
    case Work = 'work';
    case Reversal = 'reversal';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }

    public function getColor(): string
    {
        return $this === self::Work ? 'success' : 'gray';
    }
}
