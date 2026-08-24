<?php

namespace App\Models;

use App\Enums\SystemAuthorizationDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SystemAuthorizationDecisionRecord extends Model
{
    use HasFactory;

    protected $table = 'system_authorization_decisions';

    protected $fillable = ['system_authorization_package_id', 'version', 'package_snapshot', 'decision', 'conditions', 'rationale', 'decided_by', 'decided_at', 'valid_until', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'package_snapshot' => 'array', 'decision' => SystemAuthorizationDecision::class, 'conditions' => 'array', 'decided_at' => 'datetime', 'valid_until' => 'date'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('System authorization decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('System authorization decisions are retained evidence.'));
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(SystemAuthorizationPackage::class, 'system_authorization_package_id');
    }

    public function authorizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
