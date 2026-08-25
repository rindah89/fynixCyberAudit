<?php

namespace App\Models;

use App\Enums\ComplianceCaseCategory;
use App\Enums\ComplianceCasePriority;
use Database\Factories\ComplianceCaseIntakeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ComplianceCaseIntake extends Model
{
    use HasFactory;

    protected $fillable = ['reference', 'title', 'category', 'priority', 'allegation', 'source_channel', 'source_reference', 'confidential', 'reporter_message', 'submitted_by', 'reporter_snapshot', 'submitted_at', 'fingerprint'];

    protected $casts = ['category' => ComplianceCaseCategory::class, 'priority' => ComplianceCasePriority::class, 'confidential' => 'boolean', 'reporter_snapshot' => 'array', 'submitted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Governed compliance case intakes are immutable.'));
        static::deleting(fn () => throw new \LogicException('Governed compliance case intakes are retained.'));
    }

    protected static function newFactory(): ComplianceCaseIntakeFactory
    {
        return ComplianceCaseIntakeFactory::new();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by')->withTrashed();
    }

    public function decision(): HasOne
    {
        return $this->hasOne(ComplianceCaseIntakeDisposition::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ComplianceCaseIntakeMessage::class)->orderBy('version');
    }
}
