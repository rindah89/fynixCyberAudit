<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ThirdPartyEngagementStatus: string implements HasColor, HasLabel
{
    case Proposed = 'proposed';
    case DueDiligence = 'due_diligence';
    case Approved = 'approved';
    case Active = 'active';
    case RenewalReview = 'renewal_review';
    case Exited = 'exited';
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proposed => __('Proposed'), self::DueDiligence => __('Due Diligence'), self::Approved => __('Approved'),
            self::Active => __('Active'), self::RenewalReview => __('Renewal Review'), self::Exited => __('Exited'), self::Rejected => __('Rejected'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Proposed => 'info', self::DueDiligence, self::RenewalReview => 'warning', self::Approved, self::Active => 'success', self::Exited => 'gray', self::Rejected => 'danger',
        };
    }

    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Proposed => [self::DueDiligence, self::Rejected],
            self::DueDiligence => [self::Approved, self::Rejected],
            self::Approved => [self::Active, self::Rejected],
            self::Active => [self::RenewalReview, self::Exited],
            self::RenewalReview => [self::Active, self::Exited],
            self::Exited, self::Rejected => [],
        };
    }
}
