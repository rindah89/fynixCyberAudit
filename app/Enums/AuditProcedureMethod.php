<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum AuditProcedureMethod: string implements HasLabel
{
    case Inquiry = 'inquiry';
    case Inspection = 'inspection';
    case Observation = 'observation';
    case Reperformance = 'reperformance';
    case Analytics = 'analytics';

    public function getLabel(): string
    {
        return __(str($this->value)->headline()->toString());
    }
}
