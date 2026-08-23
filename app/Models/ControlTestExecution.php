<?php

namespace App\Models;

use App\Enums\ControlTestOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ControlTestExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'control_test_definition_id', 'executed_by', 'observed_value', 'metric_type', 'operator', 'expected_value', 'outcome', 'result_reason',
        'notes', 'evidence_reference', 'executed_at',
    ];

    protected $casts = ['outcome' => ControlTestOutcome::class, 'executed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Control test executions are immutable. Record a new execution instead.'));
        static::deleting(fn () => throw new LogicException('Control test executions are immutable.'));
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ControlTestDefinition::class, 'control_test_definition_id');
    }

    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executed_by');
    }

    public function finding(): HasOne
    {
        return $this->hasOne(ControlTestFinding::class);
    }
}
