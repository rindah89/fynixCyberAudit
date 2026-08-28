<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecoveryEvidence extends Model
{
    protected $table = 'recovery_evidence';

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'immutable_datetime'];
}
