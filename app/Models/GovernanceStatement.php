<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GovernanceStatement extends Model
{
    protected $fillable = [
        'statement_id', 'delivery_id', 'source', 'tenant_id', 'schema_version',
        'period_start', 'period_end', 'occurred_at', 'payload_sha256',
    ];

    protected function casts(): array
    {
        return ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime', 'occurred_at' => 'immutable_datetime'];
    }

    public function controlResults(): HasMany
    {
        return $this->hasMany(GovernanceControlResult::class);
    }
}
