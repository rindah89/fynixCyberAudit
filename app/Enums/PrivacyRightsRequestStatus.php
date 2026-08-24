<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PrivacyRightsRequestStatus: string implements HasColor, HasLabel
{
    case Received = 'received';
    case IdentityVerification = 'identity_verification';
    case InProgress = 'in_progress';
    case Fulfilled = 'fulfilled';
    case Denied = 'denied';
    case Withdrawn = 'withdrawn';

    public function getLabel(): string
    {
        return match ($this) {
            self::Received => __('Received'), self::IdentityVerification => __('Identity Verification'), self::InProgress => __('In Progress'),
            self::Fulfilled => __('Fulfilled'), self::Denied => __('Denied'), self::Withdrawn => __('Withdrawn'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Received => 'info', self::IdentityVerification, self::InProgress => 'warning', self::Fulfilled => 'success', self::Denied => 'danger', self::Withdrawn => 'gray',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::IdentityVerification, self::Withdrawn],
            self::IdentityVerification => [self::InProgress, self::Denied, self::Withdrawn],
            self::InProgress => [self::Fulfilled, self::Denied, self::Withdrawn],
            self::Fulfilled, self::Denied, self::Withdrawn => [],
        };
    }
}
