<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceException extends Model
{
    protected $fillable = [
        'source', 'tenant_id', 'control_id', 'status', 'severity', 'reason', 'resolution_notes', 'owner',
        'due_at', 'first_detected_at', 'last_detected_at', 'resolved_at', 'latest_control_result_id',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime', 'first_detected_at' => 'immutable_datetime',
            'last_detected_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime',
        ];
    }
}
