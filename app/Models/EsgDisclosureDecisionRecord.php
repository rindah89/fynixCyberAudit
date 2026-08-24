<?php

namespace App\Models;

use App\Enums\EsgDisclosureDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EsgDisclosureDecisionRecord extends Model
{
    use HasFactory;

    protected $table = 'esg_disclosure_decisions';

    protected $fillable = ['esg_disclosure_id', 'version', 'disclosure_snapshot', 'decision', 'rationale', 'decided_by', 'decided_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'disclosure_snapshot' => 'array', 'decision' => EsgDisclosureDecision::class, 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG disclosure decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG disclosure decisions are retained evidence.'));
    }

    public function disclosure(): BelongsTo
    {
        return $this->belongsTo(EsgDisclosure::class, 'esg_disclosure_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
