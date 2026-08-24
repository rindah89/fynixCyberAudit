<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class EsgDisclosure extends Model
{
    use HasFactory;

    protected $fillable = ['disclosure_key', 'code', 'version', 'title', 'reporting_period_start', 'reporting_period_end', 'framework_references', 'narrative', 'validation_snapshot', 'prepared_by', 'prepared_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'reporting_period_start' => 'date', 'reporting_period_end' => 'date', 'framework_references' => 'array', 'validation_snapshot' => 'array', 'prepared_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG disclosure versions are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG disclosure versions are retained evidence.'));
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by')->withTrashed();
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(EsgDisclosureDecisionRecord::class)->with('decider:id,name')->orderBy('version');
    }

    public function validations(): BelongsToMany
    {
        return $this->belongsToMany(EsgDataValidation::class, 'esg_disclosure_validation')->withTimestamps();
    }

    public function latestDecision(): HasOne
    {
        return $this->hasOne(EsgDisclosureDecisionRecord::class)->latestOfMany('version');
    }
}
