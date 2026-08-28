<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProcessorRegisterCertification extends Model
{
    protected $guarded = [];

    protected $casts = ['valid_until' => 'immutable_date', 'decided_at' => 'immutable_datetime'];
}
