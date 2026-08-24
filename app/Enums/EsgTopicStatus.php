<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgTopicStatus: string implements HasColor, HasLabel
{
    case Draft = 'Draft';
    case Material = 'Material';
    case NotMaterial = 'Not Material';
    case ReviewRequired = 'Review Required';
    case Retired = 'Retired';

    public function getLabel(): ?string
    {
        return $this->value;
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Draft => 'info',self::Material => 'success',self::NotMaterial,self::Retired => 'gray',self::ReviewRequired => 'warning'
        };
    }
}
