<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComplianceCaseInvestigationProcedureResult: string implements HasColor, HasLabel
{
    case Completed = 'completed';
    case ExceptionIdentified = 'exception_identified';
    case NotApplicable = 'not_applicable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Completed => __('Completed'),
            self::ExceptionIdentified => __('Exception Identified'),
            self::NotApplicable => __('Not Applicable'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::ExceptionIdentified => 'danger',
            self::NotApplicable => 'gray',
        };
    }
}
