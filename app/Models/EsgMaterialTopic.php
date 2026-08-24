<?php

namespace App\Models;

use App\Enums\EsgPillar;
use App\Enums\EsgTopicStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EsgMaterialTopic extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'pillar', 'status', 'owner_id', 'description', 'impact_context', 'risk_context', 'opportunity_context', 'stakeholder_groups', 'framework_references', 'organizational_boundary', 'source_reference', 'next_review_at', 'governed_at'];

    protected $casts = ['pillar' => EsgPillar::class, 'status' => EsgTopicStatus::class, 'stakeholder_groups' => 'array', 'framework_references' => 'array', 'next_review_at' => 'date', 'governed_at' => 'datetime'];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EsgMaterialTopicVersion::class)->with('actor:id,name')->orderBy('version');
    }

    public function assessments(): HasMany
    {
        return $this->hasMany(EsgMaterialityAssessment::class)->with(['assessor:id,name', 'topicVersion:id,version,fingerprint'])->orderBy('version');
    }

    public function latestVersion(): HasOne
    {
        return $this->hasOne(EsgMaterialTopicVersion::class)->latestOfMany('version');
    }

    public function latestAssessment(): HasOne
    {
        return $this->hasOne(EsgMaterialityAssessment::class)->latestOfMany('version');
    }

    public function goals(): HasMany
    {
        return $this->hasMany(EsgGoal::class, 'esg_material_topic_id')->with(['owner:id,name', 'creator:id,name'])->orderBy('id');
    }
}
