<?php

namespace App\Models;

use App\Enums\ComplianceCaseIntakeAudience;
use Database\Factories\ComplianceCaseIntakeMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseIntakeMessage extends Model
{
    use HasFactory;

    protected $fillable = ['compliance_case_intake_id', 'version', 'audience', 'message', 'actor_id', 'actor_snapshot', 'intake_snapshot', 'disposition_snapshot', 'recorded_at', 'fingerprint'];

    protected $casts = ['audience' => ComplianceCaseIntakeAudience::class, 'actor_snapshot' => 'array', 'intake_snapshot' => 'array', 'disposition_snapshot' => 'array', 'recorded_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed compliance case intake messages are immutable.'));
        static::deleting(fn () => throw new \LogicException('Governed compliance case intake messages are retained.'));
    }

    protected static function newFactory(): ComplianceCaseIntakeMessageFactory
    {
        return ComplianceCaseIntakeMessageFactory::new();
    }

    public function intake(): BelongsTo
    {
        return $this->belongsTo(ComplianceCaseIntake::class, 'compliance_case_intake_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }

    public function acknowledgement(): HasOne
    {
        return $this->hasOne(ComplianceCaseIntakeMessageAcknowledgement::class);
    }
}
