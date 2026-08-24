<?php

namespace App\Models;

use App\Enums\SystemAuthorizationMonitoringOutcome;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SystemAuthorizationMonitoringReview extends Model
{
    use HasFactory;

    protected $fillable = ['system_authorization_package_id', 'version', 'package_snapshot', 'decision_snapshot', 'metrics', 'findings', 'outcome', 'required_actions', 'summary', 'reviewed_by', 'reviewed_at', 'next_review_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'package_snapshot' => 'array', 'decision_snapshot' => 'array', 'metrics' => 'array', 'findings' => 'array', 'outcome' => SystemAuthorizationMonitoringOutcome::class, 'required_actions' => 'array', 'reviewed_at' => 'datetime', 'next_review_at' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('System authorization monitoring reviews are append-only.'));
        static::deleting(fn () => throw new LogicException('System authorization monitoring reviews are retained evidence.'));
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SystemAuthorizationPackage::class, 'system_authorization_package_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by')->withTrashed();
    }
}
