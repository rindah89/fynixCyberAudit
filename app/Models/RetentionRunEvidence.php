<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RetentionRunEvidence extends Model
{
    protected $table = 'retention_run_evidence';

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime'];
}
