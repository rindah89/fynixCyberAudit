<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PolicyExceptionMonitoringState: string implements HasColor, HasLabel
{
    case Legacy = 'legacy';
    case Pending = 'pending';
    case Denied = 'denied';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case ReviewRequired = 'review_required';
    case ReviewOverdue = 'review_overdue';
    case ActionRequired = 'action_required';
    case MonitoringCurrent = 'monitoring_current';

    public function getLabel(): string
    {
        return match ($this) {
            self::Legacy => __('Legacy'),
            self::Pending => __('Pending approval'),
            self::Denied => __('Denied'),
            self::Revoked => __('Revoked'),
            self::Expired => __('Expired'),
            self::ReviewRequired => __('Review required'),
            self::ReviewOverdue => __('Review overdue'),
            self::ActionRequired => __('Action required'),
            self::MonitoringCurrent => __('Monitoring current'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::MonitoringCurrent => 'success',
            self::Pending, self::ReviewRequired, self::ReviewOverdue => 'warning',
            self::Denied, self::Revoked, self::Expired, self::ActionRequired => 'danger',
            self::Legacy => 'gray',
        };
    }
}
