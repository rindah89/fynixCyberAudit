<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgDataValidationOutcome: string implements HasColor, HasLabel
{
    case Validated = 'validated';
    case Rejected = 'rejected';
    case ChangesRequired = 'changes_required';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Validated => 'Validated', self::Rejected => 'Rejected', self::ChangesRequired => 'Changes required',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Validated => 'success', self::Rejected => 'danger', self::ChangesRequired => 'warning',
        };
    }
}
