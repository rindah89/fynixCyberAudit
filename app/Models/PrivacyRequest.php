<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivacyRequest extends Model
{
    protected $guarded = [];

    protected $casts = ['requested_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
}
