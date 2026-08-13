<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuiteInboundDelivery extends Model
{
    protected $fillable = [
        'delivery_id',
        'event_type',
        'source',
        'outcome',
    ];
}
