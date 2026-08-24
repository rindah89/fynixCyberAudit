<?php

namespace App\Models;

use App\Enums\IncidentNotificationAudience;
use App\Enums\IncidentNotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentNotification extends Model
{
    protected $appends = ['deadline_status'];

    protected $fillable = [
        'incident_id', 'audience', 'framework', 'recipient', 'status', 'deadline_at',
        'sent_at', 'delivery_reference', 'governed_at',
    ];

    protected $casts = [
        'audience' => IncidentNotificationAudience::class,
        'status' => IncidentNotificationStatus::class,
        'deadline_at' => 'datetime', 'sent_at' => 'datetime', 'governed_at' => 'datetime',
    ];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(IncidentNotificationEvent::class)->orderBy('version');
    }

    public function getDeadlineStatusAttribute(): string
    {
        if (! in_array($this->status, [IncidentNotificationStatus::Required, IncidentNotificationStatus::Prepared], true)
            || $this->deadline_at === null) {
            return 'not_applicable';
        }

        return $this->deadline_at->isPast() ? 'overdue' : 'pending';
    }
}
