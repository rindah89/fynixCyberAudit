<?php

namespace App\Enums;

use App\Models\AiGovernanceIssue;
use App\Models\ControlTestFinding;
use App\Models\ResilienceIssue;
use App\Models\RiskGovernanceIssue;
use App\Models\VendorRiskIssue;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

enum GovernanceIssueType: string implements HasLabel
{
    case Risk = 'risk';
    case Vendor = 'vendor';
    case Ai = 'ai';
    case Resilience = 'resilience';
    case ControlTest = 'control_test';

    public function modelClass(): string
    {
        return match ($this) {
            self::Risk => RiskGovernanceIssue::class,
            self::Vendor => VendorRiskIssue::class,
            self::Ai => AiGovernanceIssue::class,
            self::Resilience => ResilienceIssue::class,
            self::ControlTest => ControlTestFinding::class,
        };
    }

    public function findOrFail(int $id): Model
    {
        return $this->modelClass()::query()->findOrFail($id);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Risk => __('Risk governance'),
            self::Vendor => __('Third-party risk'),
            self::Ai => __('AI governance'),
            self::Resilience => __('Operational resilience'),
            self::ControlTest => __('Control test'),
        };
    }

    public static function fromModelClass(string $class): self
    {
        return collect(self::cases())->firstOrFail(fn (self $type): bool => $type->modelClass() === $class);
    }
}
