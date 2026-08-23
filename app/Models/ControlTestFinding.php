<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ControlTestFinding extends Model
{
    protected $fillable = ['control_test_execution_id', 'control_id', 'owner_id', 'title', 'description', 'status', 'detected_at'];

    protected $casts = ['detected_at' => 'datetime'];

    public function execution(): BelongsTo
    {
        return $this->belongsTo(ControlTestExecution::class, 'control_test_execution_id');
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
