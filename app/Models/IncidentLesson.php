<?php

namespace App\Models;

use App\Enums\IncidentLessonArea;
use App\Enums\IncidentLessonStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentLesson extends Model
{
    protected $appends = ['target_status'];

    protected $fillable = [
        'incident_id', 'area', 'observation', 'recommendation', 'owner_id', 'target_date', 'status', 'governed_at',
    ];

    protected $casts = [
        'area' => IncidentLessonArea::class, 'status' => IncidentLessonStatus::class,
        'target_date' => 'date', 'governed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentLessonEvent::class)->orderBy('version');
    }

    public function getTargetStatusAttribute(): string
    {
        if (! in_array($this->status, [IncidentLessonStatus::Proposed, IncidentLessonStatus::InProgress], true)
            || $this->target_date === null) {
            return 'not_applicable';
        }

        return $this->target_date->endOfDay()->isPast() ? 'overdue' : 'pending';
    }
}
