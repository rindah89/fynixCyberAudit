<?php

namespace App\Models;

use App\Enums\ComplianceCaseInterviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComplianceCaseInterview extends Model
{
    use HasFactory;

    protected $fillable = [
        'compliance_case_id', 'subject_user_id', 'subject_reference', 'interviewer_id', 'status',
        'scheduled_at', 'conducted_at', 'location', 'purpose', 'summary', 'cancellation_reason',
    ];

    protected $casts = [
        'status' => ComplianceCaseInterviewStatus::class,
        'scheduled_at' => 'datetime',
        'conducted_at' => 'datetime',
    ];

    public function complianceCase(): BelongsTo
    {
        return $this->belongsTo(ComplianceCase::class);
    }

    public function subjectUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'subject_user_id')->withTrashed();
    }

    public function interviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'interviewer_id')->withTrashed();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ComplianceCaseInterviewEvent::class)->orderBy('version');
    }
}
