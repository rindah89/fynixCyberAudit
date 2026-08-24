<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EsgMaterialTopicVersion extends Model
{
    use HasFactory;

    protected $fillable = ['esg_material_topic_id', 'version', 'topic_snapshot', 'change_summary', 'recorded_by', 'recorded_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'topic_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG topic versions are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG topic versions are retained evidence.'));
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(EsgMaterialTopic::class, 'esg_material_topic_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }
}
