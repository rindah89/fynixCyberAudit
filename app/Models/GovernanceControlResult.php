<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GovernanceControlResult extends Model
{
    protected $fillable = [
        'governance_statement_id', 'control_id', 'status', 'observed_at',
        'summary', 'evidence_refs', 'metrics',
    ];

    protected function casts(): array
    {
        return ['observed_at' => 'immutable_datetime', 'evidence_refs' => 'array', 'metrics' => 'array'];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(GovernanceStatement::class, 'governance_statement_id');
    }
}
