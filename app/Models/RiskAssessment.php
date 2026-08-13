<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RiskAssessment extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'owner_id',
        'mode',
        'status',
        'recurrence',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(function (self $assessment): void {
            $assessment->collaborators()->firstOrCreate(
                ['user_id' => $assessment->owner_id],
                ['role' => 'owner'],
            );
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(RiskAssessmentItem::class);
    }

    public function collaborators(): HasMany
    {
        return $this->hasMany(RiskAssessmentCollaborator::class);
    }

    public function isCollaborator(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if ((int) $this->owner_id === (int) $user->id) {
            return true;
        }

        return $this->collaborators()->where('user_id', $user->id)->exists();
    }
}
