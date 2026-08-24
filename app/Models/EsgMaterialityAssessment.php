<?php

namespace App\Models;

use App\Enums\EsgMaterialityDecision;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EsgMaterialityAssessment extends Model
{
    use HasFactory;

    protected $fillable = ['esg_material_topic_id', 'version', 'topic_version_id', 'topic_snapshot', 'impact_materiality', 'financial_materiality', 'stakeholder_evidence', 'methodology', 'decision', 'decision_summary', 'assessed_by', 'assessed_at', 'next_review_at', 'fingerprint'];

    protected $casts = ['version' => 'integer', 'impact_materiality' => 'integer', 'financial_materiality' => 'integer', 'decision' => EsgMaterialityDecision::class, 'assessed_at' => 'datetime', 'next_review_at' => 'date', 'topic_snapshot' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('ESG materiality assessments are append-only.'));
        static::deleting(fn () => throw new LogicException('ESG materiality assessments are retained evidence.'));
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(EsgMaterialTopic::class, 'esg_material_topic_id');
    }

    public function topicVersion(): BelongsTo
    {
        return $this->belongsTo(EsgMaterialTopicVersion::class, 'topic_version_id');
    }

    public function assessor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assessed_by')->withTrashed();
    }
}
