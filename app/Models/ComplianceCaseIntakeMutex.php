<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ComplianceCaseIntakeMutex extends Model
{
    protected $table = 'compliance_case_intake_mutexes';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'int';

    protected $fillable = ['id'];
}
