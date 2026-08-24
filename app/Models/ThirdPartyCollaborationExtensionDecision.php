<?php

namespace App\Models;

use App\Enums\ThirdPartyCollaborationExtensionDecision as Decision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyCollaborationExtensionDecision extends Model
{
    use HasFactory;

    protected $fillable = ['third_party_collaboration_extension_id', 'decision', 'summary', 'decided_by', 'decider_snapshot', 'extension_snapshot', 'decided_at', 'fingerprint'];

    protected $casts = ['decision' => Decision::class, 'decider_snapshot' => 'array', 'extension_snapshot' => 'array', 'decided_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration extension decisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration extension decisions are retained governance history.'));
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyCollaborationExtension::class, 'third_party_collaboration_extension_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by')->withTrashed();
    }
}
