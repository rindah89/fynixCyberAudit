<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuditableEntityType: string implements HasLabel
{
    case BusinessUnit = 'business_unit';
    case BusinessProcess = 'business_process';
    case LegalEntity = 'legal_entity';
    case Technology = 'technology';
    case ThirdParty = 'third_party';
    case Program = 'program';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}
