<?php

namespace App\Models;

use App\Enums\IncidentPhase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentEvidence extends Model
{
    protected $table = 'incident_evidence';

    protected $fillable = [
        'incident_id',
        'type',
        'filename',
        'path',
        'hash',
        'phase',
        'source',
        'chain_of_custody',
        'uploaded_by',
    ];

    protected $casts = [
        'phase' => IncidentPhase::class,
        'chain_of_custody' => 'boolean',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
