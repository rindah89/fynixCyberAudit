<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentTask extends Model
{
    protected $appends = ['governance_status'];

    protected $fillable = [
        'incident_id',
        'title',
        'phase',
        'status',
        'priority',
        'assignee_id',
        'due_date',
        'governed_at',
    ];

    protected $casts = [
        'phase' => IncidentPhase::class,
        'due_date' => 'date',
        'governed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentTaskEvent::class)->orderBy('version');
    }

    public function getGovernanceStatusAttribute(): string
    {
        return $this->governed_at === null ? 'legacy' : 'governed';
    }
}
