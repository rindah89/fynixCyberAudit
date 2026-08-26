<?php

namespace App\Models;

use App\Enums\ComplianceCaseConflictDecision as ComplianceCaseConflictDecisionEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseConflictDecision extends Model
{
    protected $fillable = [
        'compliance_case_conflict_declaration_id', 'decision', 'summary', 'decided_by', 'decider_snapshot',
        'declaration_snapshot', 'decided_at', 'fingerprint',
    ];

    protected $hidden = ['declaration_snapshot'];

    protected $casts = [
        'decision' => ComplianceCaseConflictDecisionEnum::class, 'decider_snapshot' => 'array',
        'declaration_snapshot' => 'array', 'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed conflict decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed conflict decisions are retained.'));
    }

    public function declaration(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseConflictDeclaration::class, 'compliance_case_conflict_declaration_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
