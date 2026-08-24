<?php

namespace App\Models;

use App\Enums\FourthPartyCriticality;
use App\Enums\FourthPartyDependencyCategory;
use App\Enums\FourthPartyDependencyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class VendorFourthPartyDependency extends Model
{
    use HasFactory;

    protected $fillable = ['vendor_id', 'fourth_party_vendor_id', 'business_service_id', 'recorded_by', 'dependency_key', 'version', 'status', 'category', 'criticality', 'fourth_party_name', 'service_description', 'data_access', 'source_reference', 'rationale', 'governance_snapshot', 'recorded_at'];

    protected $casts = [
        'status' => FourthPartyDependencyStatus::class,
        'category' => FourthPartyDependencyCategory::class,
        'criticality' => FourthPartyCriticality::class,
        'data_access' => 'boolean',
        'governance_snapshot' => 'array',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Fourth-party dependency records are immutable. Record a new version instead.'));
        static::deleting(fn () => throw new LogicException('Fourth-party dependency records are immutable.'));
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    public function fourthPartyVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'fourth_party_vendor_id');
    }

    public function businessService(): BelongsTo
    {
        return $this->belongsTo(BusinessService::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
