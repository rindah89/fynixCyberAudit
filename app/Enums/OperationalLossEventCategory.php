<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum OperationalLossEventCategory: string implements HasLabel
{
    case InternalFraud = 'internal_fraud';
    case ExternalFraud = 'external_fraud';
    case EmploymentPractices = 'employment_practices';
    case ClientsProductsBusinessPractices = 'clients_products_business_practices';
    case PhysicalAssetDamage = 'physical_asset_damage';
    case BusinessDisruptionSystemFailure = 'business_disruption_system_failure';
    case ExecutionDeliveryProcessManagement = 'execution_delivery_process_management';
    case Other = 'other';

    public function getLabel(): string
    {
        return __(str($this->value)->replace('_', ' ')->title()->toString());
    }
}
