<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceCaseMutex extends Model
{
    protected $table = 'compliance_case_mutexes';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id'];
}
