<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Incident extends Model
{
    use HasFactory;

    protected $fillable = [
        'number',
        'title',
        'type',
        'severity',
        'status',
        'phase',
        'lead_id',
        'reporter_id',
        'detected_at',
        'involves_data',
        'involves_pii',
        'is_breach',
        'root_cause',
        'business_impact',
        'closure',
        'phase_timestamps',
    ];

    protected $casts = [
        'phase' => IncidentPhase::class,
        'detected_at' => 'datetime',
        'involves_data' => 'boolean',
        'involves_pii' => 'boolean',
        'is_breach' => 'boolean',
        'phase_timestamps' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(IncidentTask::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(IncidentEvidence::class);
    }

    public function phaseTimestamp(IncidentPhase $phase): ?Carbon
    {
        $raw = $this->phase_timestamps[$phase->value] ?? null;

        return $raw ? Carbon::parse($raw) : null;
    }
}
