<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseInterviewEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_interview_id', 'version', 'event_type', 'before_snapshot', 'after_snapshot',
        'rationale', 'recorded_by', 'recorded_at', 'fingerprint',
    ];

    protected $casts = ['before_snapshot' => 'array', 'after_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed interview events are append-only.'));
        static::deleting(fn () => throw new \LogicException('Governed interview events are append-only.'));
    }

    public function interview(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseInterview::class, 'compliance_case_interview_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
