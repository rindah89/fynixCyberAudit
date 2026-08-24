<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class PolicyAcknowledgementCampaign extends Model
{
    use HasFactory;

    protected $fillable = ['policy_id', 'version', 'title', 'instructions', 'due_at', 'launched_by', 'launched_at', 'closed_by', 'closed_at', 'policy_snapshot', 'policy_fingerprint'];

    protected $casts = ['due_at' => 'datetime', 'launched_at' => 'datetime', 'closed_at' => 'datetime', 'policy_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(function (self $campaign): void {
            $changed = array_keys($campaign->getDirty());
            if ($campaign->getOriginal('closed_at') || array_diff($changed, ['closed_by', 'closed_at']) !== []) {
                throw new LogicException('Launched acknowledgement campaigns are immutable except for one governed closure.');
            }
        });
        static::deleting(fn () => throw new LogicException('Policy acknowledgement campaigns are immutable.'));
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class)->withTrashed();
    }

    public function launcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'launched_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(PolicyAcknowledgementAssignment::class);
    }

    public function getCampaignStatusAttribute(): string
    {
        if ($this->closed_at) {
            return 'closed';
        }
        $total = isset($this->assignments_count) ? (int) $this->assignments_count : $this->assignments()->count();
        $acknowledged = isset($this->acknowledged_count) ? (int) $this->acknowledged_count : $this->assignments()->has('acknowledgement')->count();
        if ($total > 0 && $acknowledged === $total) {
            return 'complete';
        }

        return $this->due_at->isPast() ? 'overdue' : 'active';
    }
}
