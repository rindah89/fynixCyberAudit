<?php

namespace App\Models;

use App\Enums\PrivacyActivityStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrivacyProcessingActivity extends Model
{
    use HasFactory;

    protected $fillable = ['number', 'name', 'status', 'owner_id', 'purpose', 'lawful_basis', 'data_subject_categories', 'personal_data_categories', 'special_category_data', 'recipient_categories', 'systems_and_vendors', 'processing_locations', 'cross_border_transfer', 'transfer_safeguards', 'retention_period', 'security_measures', 'source_reference', 'next_review_at', 'governed_at'];

    protected $casts = ['status' => PrivacyActivityStatus::class, 'data_subject_categories' => 'array', 'personal_data_categories' => 'array', 'special_category_data' => 'boolean', 'recipient_categories' => 'array', 'systems_and_vendors' => 'array', 'processing_locations' => 'array', 'cross_border_transfer' => 'boolean', 'next_review_at' => 'date', 'governed_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PrivacyActivityVersion::class)->orderBy('version');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(PrivacyImpactAssessment::class)->orderBy('version');
    }
}
