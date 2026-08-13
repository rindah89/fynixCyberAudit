<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SuiteInboundHighWater extends Model
{
    protected $table = 'suite_inbound_high_water';

    protected $fillable = ['local_tenant_id', 'source', 'entity_type', 'entity_id', 'occurred_at'];

    protected $casts = ['occurred_at' => 'immutable_datetime'];
}
