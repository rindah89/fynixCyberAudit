<?php

namespace App\Models;

use Database\Factories\ComplianceCaseIntakeMessageAcknowledgementFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComplianceCaseIntakeMessageAcknowledgement extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_intake_message_id', 'recipient_id', 'recipient_snapshot', 'message_snapshot', 'acknowledged_at', 'fingerprint'];

    protected $casts = ['recipient_snapshot' => 'array', 'message_snapshot' => 'array', 'acknowledged_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed intake-message acknowledgements are immutable.'));
        static::deleting(fn () => throw new \LogicException('Governed intake-message acknowledgements are retained.'));
    }

    protected static function newFactory(): ComplianceCaseIntakeMessageAcknowledgementFactory
    {
        return ComplianceCaseIntakeMessageAcknowledgementFactory::new();
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseIntakeMessage::class, 'compliance_case_intake_message_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id')->withTrashed();
    }
}
