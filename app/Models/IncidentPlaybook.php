<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentPlaybook extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'incident_type',
        'description',
    ];

    public function tasks(): HasMany
    {
        return $this->hasMany(IncidentPlaybookTask::class);
    }
}
