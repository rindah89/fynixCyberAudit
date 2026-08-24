<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum FourthPartyDependencyCategory: string implements HasLabel
{
    case CloudInfrastructure = 'cloud_infrastructure';
    case DataProcessing = 'data_processing';
    case TechnologyService = 'technology_service';
    case FinancialService = 'financial_service';
    case Logistics = 'logistics';
    case ProfessionalService = 'professional_service';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}
