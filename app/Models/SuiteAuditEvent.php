<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SuiteAuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['occurred_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Suite audit events are append-only.'));
        static::deleting(fn (): never => throw new LogicException('Suite audit events are append-only.'));
    }
}
