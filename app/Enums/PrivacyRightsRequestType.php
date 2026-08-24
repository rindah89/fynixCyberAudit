<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PrivacyRightsRequestType: string implements HasLabel
{
    case Access = 'access';
    case Correction = 'correction';
    case Deletion = 'deletion';
    case Portability = 'portability';
    case Restriction = 'restriction';
    case Objection = 'objection';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Access => __('Access'), self::Correction => __('Correction'), self::Deletion => __('Deletion'),
            self::Portability => __('Portability'), self::Restriction => __('Restriction'), self::Objection => __('Objection'), self::Other => __('Other'),
        };
    }
}
