<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentNotificationStatus: string implements HasColor, HasLabel
{
    case AssessmentPending = 'Assessment Pending';
    case Required = 'Required';
    case NotRequired = 'Not Required';
    case Prepared = 'Prepared';
    case Sent = 'Sent';
    case Cancelled = 'Cancelled';

    public function getLabel(): string
    {
        return __($this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::AssessmentPending, self::Prepared => 'warning',
            self::Required => 'danger',
            self::Sent, self::NotRequired => 'success',
            self::Cancelled => 'gray',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::AssessmentPending => [self::Required, self::NotRequired, self::Cancelled],
            self::Required => [self::Prepared, self::NotRequired, self::Cancelled],
            self::Prepared => [self::Sent, self::Cancelled],
            self::NotRequired, self::Sent, self::Cancelled => [],
        };
    }
}
