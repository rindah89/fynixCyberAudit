<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class IncidentNumberSequence extends Model
{
    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['year', 'last_number'];
}
