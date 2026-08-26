<?php

namespace App\Models;

use App\Enums\ComplianceCaseMilestoneStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ComplianceCaseMilestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'compliance_case_event_id', 'version', 'title', 'description', 'owner_id',
        'owner_snapshot', 'due_at', 'required', 'status', 'defined_by', 'definer_snapshot', 'case_snapshot',
        'defined_at', 'fingerprint',
    ];

    protected $hidden = ['case_snapshot'];

    protected $casts = [
        'owner_snapshot' => 'array', 'definer_snapshot' => 'array', 'case_snapshot' => 'array',
        'due_at' => 'datetime', 'required' => 'boolean', 'status' => ComplianceCaseMilestoneStatus::class,
        'defined_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Governed milestones are retained.'));
    }

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ComplianceCaseMilestoneEvent::class);
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(ComplianceCaseMilestoneDelivery::class);
    }
}
