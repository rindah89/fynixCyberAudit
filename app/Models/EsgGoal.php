<?php

namespace App\Models;

use App\Enums\EsgGoalStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class EsgGoal extends Model
{
    use HasFactory;

    protected $fillable = ['esg_material_topic_id', 'code', 'title', 'description', 'owner_id', 'status', 'baseline_date', 'target_date', 'topic_snapshot', 'assessment_snapshot', 'created_by', 'governed_at', 'fingerprint'];

    protected $casts = ['status' => EsgGoalStatus::class, 'baseline_date' => 'date', 'target_date' => 'date', 'topic_snapshot' => 'array', 'assessment_snapshot' => 'array', 'governed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $goal): void {
            if (array_diff(array_keys($goal->getDirty()), ['status']) !== []) {
                throw new LogicException('ESG goal definition evidence is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('ESG goals are retained evidence.'));
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(EsgMaterialTopic::class, 'esg_material_topic_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function kpis(): HasMany
    {
        return $this->hasMany(EsgKpi::class)->with('owner:id,name')->orderBy('id');
    }
}
