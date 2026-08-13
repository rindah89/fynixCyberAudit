<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class SuiteEntityLink extends Model
{
    protected $fillable = [
        'local_type',
        'local_id',
        'system',
        'entity_type',
        'entity_id',
        'relation',
        'remote_status',
        'remote_url',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function local(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'local_type', 'local_id');
    }
}
