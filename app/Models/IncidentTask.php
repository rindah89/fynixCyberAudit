<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentTask extends Model
{
    protected $fillable = [
        'incident_id',
        'title',
        'phase',
        'status',
        'priority',
        'assignee_id',
        'due_date',
    ];

    protected $casts = [
        'phase' => IncidentPhase::class,
        'due_date' => 'date',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
