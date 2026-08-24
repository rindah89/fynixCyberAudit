<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PolicyAcknowledgementKnowledgeCheckAttempt extends Model
{
    protected $fillable = [
        'policy_acknowledgement_assignment_id', 'policy_acknowledgement_campaign_id', 'version',
        'submitted_by', 'answers_snapshot', 'question_snapshot', 'score_percentage', 'passed',
        'submitted_at', 'fingerprint',
    ];

    protected $hidden = ['question_snapshot'];

    protected $casts = [
        'answers_snapshot' => 'array', 'question_snapshot' => 'array', 'passed' => 'boolean',
        'submitted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Policy comprehension-check attempts are append-only through product interfaces.'));
        static::deleting(fn () => throw new LogicException('Policy comprehension-check attempts are append-only through product interfaces.'));
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementAssignment::class, 'policy_acknowledgement_assignment_id');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(PolicyAcknowledgementCampaign::class, 'policy_acknowledgement_campaign_id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }
}
