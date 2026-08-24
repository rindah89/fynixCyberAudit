<?php

namespace App\Models;

use App\Enums\ModelGovernanceStatus;
use App\Enums\ModelLifecycleStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class GovernedModel extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'model_type', 'tier', 'lifecycle_status', 'governance_status', 'owner_id', 'developer_id', 'intended_use', 'methodology', 'input_data', 'outputs', 'assumptions', 'limitations', 'usage_restrictions', 'implementation_reference', 'change_frequency', 'next_review_at', 'governed_at'];

    protected $casts = ['tier' => 'integer', 'lifecycle_status' => ModelLifecycleStatus::class, 'governance_status' => ModelGovernanceStatus::class, 'input_data' => 'array', 'outputs' => 'array', 'assumptions' => 'array', 'limitations' => 'array', 'usage_restrictions' => 'array', 'next_review_at' => 'date', 'governed_at' => 'datetime'];

    protected $appends = ['validation_state'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function developer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'developer_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(GovernedModelVersion::class)->orderBy('version');
    }

    public function validations(): HasMany
    {
        return $this->hasMany(ModelValidationReview::class)->orderBy('version');
    }

    public function validationReviews(): HasMany
    {
        return $this->hasMany(ModelValidationReview::class)->with(['validator:id,name', 'modelVersion:id,version,fingerprint'])->orderBy('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(GovernedModelVersion::class)->latestOfMany('version');
    }

    public function latestValidation(): HasOne
    {
        return $this->hasOne(ModelValidationReview::class)->latestOfMany('version');
    }

    public function getValidationStateAttribute(): string
    {
        if ($this->lifecycle_status === ModelLifecycleStatus::Retired) {
            return ModelLifecycleStatus::Retired->value;
        }
        $latestVersion = $this->relationLoaded('latestVersion') ? $this->latestVersion : $this->latestVersion()->first();
        $latestValidation = $this->relationLoaded('latestValidation') ? $this->latestValidation : $this->latestValidation()->first();
        if ($latestValidation === null || $latestValidation->model_version_id !== $latestVersion?->id) {
            return ModelGovernanceStatus::ValidationRequired->value;
        }
        if ($latestValidation->valid_until->copy()->endOfDay()->isPast()) {
            return ModelGovernanceStatus::ValidationExpired->value;
        }

        return $this->governance_status->value;
    }
}
