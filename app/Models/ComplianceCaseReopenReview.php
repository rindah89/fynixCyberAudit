<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ComplianceCaseReopenReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_reopen_proposal_id', 'decision', 'summary', 'reviewed_by', 'reviewer_snapshot',
        'proposal_snapshot', 'reviewed_at', 'fingerprint',
    ];

    protected $hidden = ['proposal_snapshot'];

    protected $casts = ['reviewer_snapshot' => 'array', 'proposal_snapshot' => 'array', 'reviewed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed reopen reviews are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed reopen reviews are retained.'));
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseReopenProposal::class, 'compliance_case_reopen_proposal_id');
    }
}
