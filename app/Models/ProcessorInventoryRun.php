<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessorInventoryRun extends Model
{
    protected $guarded = [];

    protected $casts = ['completed_at' => 'immutable_datetime'];
}
