<?php

namespace App\Models;

use App\Enums\PrivacyRightsRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PrivacyRightsRequestEvent extends Model
{
    use HasFactory;

    protected $fillable = ['privacy_rights_request_id', 'version', 'from_status', 'to_status', 'summary', 'request_snapshot', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['from_status' => PrivacyRightsRequestStatus::class, 'to_status' => PrivacyRightsRequestStatus::class, 'request_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Privacy rights request events are append-only.'));
        static::deleting(fn () => throw new LogicException('Privacy rights request events are append-only.'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PrivacyRightsRequest::class, 'privacy_rights_request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
