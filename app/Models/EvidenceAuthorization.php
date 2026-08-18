<?php

namespace App\Models;

use Database\Factories\EvidenceAuthorizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EvidenceAuthorization extends Model
{
    /** @use HasFactory<EvidenceAuthorizationFactory> */
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'request_json' => 'array', 'receipt_json' => 'array',
        'reviewed_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime',
        'retention_until' => 'immutable_datetime',
    ];
}
