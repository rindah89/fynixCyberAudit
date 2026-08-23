<?php

namespace App\Models;

use App\Enums\ResilienceCriticality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BusinessServiceDependency extends Model
{
    protected $fillable = [
        'business_service_id', 'dependent_service_id', 'application_id', 'asset_id', 'vendor_id', 'control_id',
        'dependency_type', 'criticality', 'notes',
    ];

    protected $casts = ['criticality' => ResilienceCriticality::class];

    protected $appends = ['target_type', 'target_label'];

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function dependentService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class, 'dependent_service_id');
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function getTargetTypeAttribute(): ?string
    {
        foreach (['dependent_service_id' => 'business_service', 'application_id' => 'application', 'asset_id' => 'asset', 'vendor_id' => 'vendor', 'control_id' => 'control'] as $field => $type) {
            if ($this->{$field}) {
                return $type;
            }
        }

        return null;
    }

    public function getTargetLabelAttribute(): ?string
    {
        return $this->dependentService?->name ?? $this->application?->name ?? $this->asset?->name ?? $this->vendor?->name ?? $this->control?->title;
    }
}
