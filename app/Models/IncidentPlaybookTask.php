<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentPlaybookTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'incident_playbook_id',
        'title',
        'phase',
        'priority',
        'sort_order',
    ];

    protected $casts = [
        'phase' => IncidentPhase::class,
    ];

    public function playbook(): BelongsTo
    {
        return $this->belongsTo(IncidentPlaybook::class, 'incident_playbook_id');
    }
}
