<?php

namespace App\Models;

use App\Enums\ControlTestFrequency;
use App\Enums\ControlTestMetricType;
use App\Enums\ControlTestOperator;
use App\Enums\ControlTestOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class ControlTestDefinition extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['frequency' => 'monthly', 'is_active' => true];

    protected $fillable = [
        'control_id', 'implementation_id', 'owner_id', 'code', 'name', 'description', 'instructions',
        'metric_type', 'operator', 'expected_value', 'frequency', 'next_run_at', 'is_active',
    ];

    protected $casts = [
        'metric_type' => ControlTestMetricType::class,
        'operator' => ControlTestOperator::class,
        'frequency' => ControlTestFrequency::class,
        'next_run_at' => 'datetime',
        'last_executed_at' => 'datetime',
        'last_outcome' => ControlTestOutcome::class,
        'is_active' => 'boolean',
    ];

    protected $appends = ['monitoring_status'];

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function implementation(): BelongsTo
    {
        return $this->belongsTo(Implementation::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(ControlTestExecution::class);
    }

    public function latestExecution(): HasOne
    {
        return $this->hasOne(ControlTestExecution::class)->latestOfMany('executed_at');
    }

    public function getMonitoringStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }
        if ($this->last_executed_at && ! $this->next_run_at) {
            return 'completed';
        }
        if ($this->next_run_at?->isFuture()) {
            return 'scheduled';
        }

        return 'due';
    }
}
