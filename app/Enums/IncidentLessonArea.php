<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum IncidentLessonArea: string implements HasLabel
{
    case People = 'People';
    case Process = 'Process';
    case Technology = 'Technology';
    case Communication = 'Communication';
    case Vendor = 'Vendor';
    case Governance = 'Governance';
    case Other = 'Other';

    public function getLabel(): string
    {
        return __($this->value);
    }
}
