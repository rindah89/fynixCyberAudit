<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceControlEvidence extends Model
{
    protected $guarded = [];

    protected $casts = [
        'observed_at' => 'immutable_datetime',
        'reviewed_at' => 'immutable_datetime',
    ];
}
