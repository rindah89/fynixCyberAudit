<?php

namespace App\Models;

use App\Enums\ComplianceCaseIntakeDecision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseIntakeDisposition extends Model
{
    protected $fillable = ['compliance_case_intake_id', 'compliance_case_id', 'decision', 'summary', 'decided_by', 'actor_snapshot', 'intake_snapshot', 'case_snapshot', 'decided_at', 'fingerprint'];

    protected $casts = ['decision' => ComplianceCaseIntakeDecision::class, 'actor_snapshot' => 'array', 'intake_snapshot' => 'array', 'case_snapshot' => 'array', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed compliance case intake dispositions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Governed compliance case intake dispositions are retained.'));
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseIntake::class, 'compliance_case_intake_id');
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
