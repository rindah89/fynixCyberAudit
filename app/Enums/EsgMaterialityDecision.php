<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EsgMaterialityDecision: string implements HasColor, HasLabel
{
    case Material = 'material';
    case NotMaterial = 'not_material';
    case ChangesRequired = 'changes_required';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::Material => 'Material',self::NotMaterial => 'Not material',self::ChangesRequired => 'Changes required'
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Material => 'success',self::NotMaterial => 'gray',self::ChangesRequired => 'warning'
        };
    }
}
