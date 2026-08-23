<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum EnterpriseScenarioProbability: string implements HasLabel
{
    case Rare = 'rare';
    case Unlikely = 'unlikely';
    case Possible = 'possible';
    case Likely = 'likely';
    case AlmostCertain = 'almost_certain';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rare => __('Rare'),
            self::Unlikely => __('Unlikely'),
            self::Possible => __('Possible'),
            self::Likely => __('Likely'),
            self::AlmostCertain => __('Almost certain'),
        };
    }
}
