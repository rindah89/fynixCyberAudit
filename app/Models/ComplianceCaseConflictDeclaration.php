<?php

namespace App\Models;

use Database\Factories\ComplianceCaseConflictDeclarationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseConflictDeclaration extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'compliance_case_event_id', 'version', 'subject_user_id', 'subject_snapshot',
        'declared_by', 'declarer_snapshot', 'nature', 'rationale', 'case_snapshot', 'latest_event_snapshot',
        'declared_at', 'fingerprint',
    ];

    protected $hidden = ['case_snapshot', 'latest_event_snapshot'];

    protected $casts = [
        'subject_snapshot' => 'array', 'declarer_snapshot' => 'array', 'case_snapshot' => 'array',
        'latest_event_snapshot' => 'array', 'declared_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed conflict declarations are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed conflict declarations are retained.'));
    }

    protected static function newFactory(): ComplianceCaseConflictDeclarationFactory
    {
        return ComplianceCaseConflictDeclarationFactory::new();
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id')->withTrashed();
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by')->withTrashed();
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ComplianceCaseConflictDecision::class);
    }
}
