<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class ComplianceCaseReopenProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'version', 'summary', 'proposed_by', 'proposer_snapshot', 'case_snapshot',
        'proposed_at', 'fingerprint',
    ];

    protected $hidden = ['case_snapshot'];

    protected $casts = ['proposer_snapshot' => 'array', 'case_snapshot' => 'array', 'proposed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Governed reopen proposals are immutable.'));
        static::deleting(fn () => throw new LogicException('Governed reopen proposals are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(ComplianceCaseReopenReview::class);
    }
}
