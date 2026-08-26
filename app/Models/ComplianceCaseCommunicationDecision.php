<?php

namespace App\Models;

use App\Enums\ComplianceCaseCommunicationDecisionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseCommunicationDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'audience', 'purpose', 'decision', 'deadline_at', 'external_reference',
        'decided_by', 'decider_snapshot', 'case_snapshot', 'decided_at', 'fingerprint',
    ];

    protected $hidden = ['case_snapshot'];

    protected $casts = [
        'decision' => ComplianceCaseCommunicationDecisionType::class, 'deadline_at' => 'datetime',
        'decider_snapshot' => 'array', 'case_snapshot' => 'array', 'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed communication decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed communication decisions are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }
}
