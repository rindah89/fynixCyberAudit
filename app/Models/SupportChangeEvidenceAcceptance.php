<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportChangeEvidenceAcceptance extends Model
{
    protected $guarded = [];

    protected $casts = [
        'request_json' => 'array', 'receipt_json' => 'array',
        'reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
        'consumed_at' => 'immutable_datetime',
    ];
}
