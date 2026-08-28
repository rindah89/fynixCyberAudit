<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GovernanceControlReview extends Model
{
    protected $guarded = [];

    protected $casts = ['decided_at' => 'immutable_datetime'];
}
