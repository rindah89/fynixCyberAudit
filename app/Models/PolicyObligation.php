<?php

namespace App\Models;

use App\Enums\PolicyAttestationOutcome;
use App\Enums\PolicyObligationFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PolicyObligation extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = [
        'frequency' => PolicyObligationFrequency::Annual->value,
        'is_active' => true,
    ];

    protected $fillable = [
        'policy_id',
        'control_id',
        'owner_id',
        'code',
        'title',
        'description',
        'frequency',
        'next_due_at',
        'is_active',
    ];

    protected $casts = [
        'frequency' => PolicyObligationFrequency::class,
        'next_due_at' => 'datetime',
        'last_attested_at' => 'datetime',
        'last_outcome' => PolicyAttestationOutcome::class,
        'is_active' => 'boolean',
    ];

    protected $appends = ['compliance_status'];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function attestations(): HasMany
    {
        return $this->hasMany(PolicyAttestation::class);
    }

    public function latestAttestation(): HasOne
    {
        return $this->hasOne(PolicyAttestation::class)->latestOfMany('attested_at');
    }

    public function getComplianceStatusAttribute(): string
    {
        if (! $this->is_active) {
            return 'inactive';
        }

        if ($this->next_due_at?->isPast()) {
            return 'overdue';
        }

        return $this->last_outcome?->value ?? 'due';
    }
}
