<?php

namespace App\Models;

use App\Enums\PolicyExceptionStatus;
use Database\Factories\PolicyExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class PolicyException
 *
 * Represents an exception to a policy in the GRC system.
 * Policy exceptions allow temporary or permanent deviations from policy requirements.
 */
class PolicyException extends Model
{
    /** @use HasFactory<PolicyExceptionFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'policy_id',
        'name',
        'description',
        'justification',
        'risk_assessment',
        'compensating_controls',
        'status',
        'requested_date',
        'effective_date',
        'expiration_date',
        'requested_by',
        'approved_by',
        'created_by',
        'updated_by',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'status' => PolicyExceptionStatus::class,
        'requested_date' => 'date',
        'effective_date' => 'date',
        'expiration_date' => 'date',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
            }
        });

        static::updating(function ($model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }

    /**
     * Get the policy that this exception belongs to.
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /**
     * Get the user who requested this exception.
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * Get the user who approved this exception.
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * Get the user who created this exception.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who last updated this exception.
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Check if the exception is currently active.
     */
    public function isActive(): bool
    {
        if ($this->status !== PolicyExceptionStatus::Approved) {
            return false;
        }

        $now = now()->startOfDay();

        if ($this->effective_date && $this->effective_date->greaterThan($now)) {
            return false;
        }

        if ($this->expiration_date && $this->expiration_date->lessThan($now)) {
            return false;
        }

        return true;
    }

    /**
     * Check if the exception has expired.
     */
    public function isExpired(): bool
    {
        if (! $this->expiration_date) {
            return false;
        }

        return $this->expiration_date->lessThan(now()->startOfDay());
    }

    /**
     * Scope a query to only include active exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', PolicyExceptionStatus::Approved)
            ->where(function ($q) {
                $q->whereNull('effective_date')
                    ->orWhereDate('effective_date', '<=', today());
            })
            ->where(function ($q) {
                $q->whereNull('expiration_date')
                    ->orWhereDate('expiration_date', '>=', today());
            });
    }

    /**
     * Scope a query to only include pending exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePending($query)
    {
        return $query->where('status', PolicyExceptionStatus::Pending);
    }

    /**
     * Scope a query to only include expired exceptions.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeExpired($query)
    {
        return $query->where('status', PolicyExceptionStatus::Approved)
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<', now());
    }
}
