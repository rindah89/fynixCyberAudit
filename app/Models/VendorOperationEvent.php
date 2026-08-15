<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class VendorOperationEvent extends Model
{
    protected $fillable = [
        'request_id',
        'operation_id',
        'delivery_id',
        'operator_subject',
        'action',
        'target',
        'outcome',
        'source_ip',
        'itsm_record',
        'before_sha256',
        'after_sha256',
        'metadata',
        'occurred_at',
        'previous_hash',
        'event_hash',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Vendor operation events are append-only.');
        });
        static::deleting(function (): never {
            throw new LogicException('Vendor operation events are append-only.');
        });
    }
}
