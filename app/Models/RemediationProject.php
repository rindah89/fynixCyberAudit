<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RemediationProject extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'status',
        'owner_id',
        'program_id',
        'start_date',
        'due_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(RemediationProjectMember::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(RemediationTask::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('owner_id', $user->id)
                ->orWhereHas('members', fn (Builder $members) => $members->where('user_id', $user->id));
        });
    }

    public function isMember(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return (int) $this->owner_id === (int) $user->id
            || $this->members()->where('user_id', $user->id)->exists();
    }
}
