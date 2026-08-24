<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyMonitoringState: string implements HasColor, HasLabel
{
    case AwaitingObservation = 'awaiting_observation';
    case ObservationOverdue = 'observation_overdue';
    case Normal = 'normal';
    case Warning = 'warning';
    case ActionRequired = 'action_required';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingObservation => __('Awaiting observation'), self::ObservationOverdue => __('Observation overdue'), self::Normal => __('Normal'),
            self::Warning => __('Warning'), self::ActionRequired => __('Action required'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Normal => 'success', self::Warning, self::ObservationOverdue => 'warning', self::ActionRequired => 'danger', self::AwaitingObservation => 'info',
        };
    }
}
