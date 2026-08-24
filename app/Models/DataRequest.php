<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class DataRequest extends Model
{
    use HasFactory, LogsActivity;

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'files' => 'array',
    ];

    protected $fillable = [
        'created_by_id',
        'assigned_to_id',
        'audit_item_id',
        'audit_id',
        'status',
        'details',
        'response',
        'files',
        'code', // Optional code for the data request, can be null, defaults to Request-{id}
    ];

    protected static function booted(): void
    {
        static::creating(fn (DataRequest $request) => self::assertCloseoutMutable((int) $request->audit_id));
        static::updating(function (DataRequest $request): void {
            self::assertCloseoutMutable((int) $request->getRawOriginal('audit_id'));
            if ((int) $request->audit_id !== (int) $request->getRawOriginal('audit_id')) {
                self::assertCloseoutMutable((int) $request->audit_id);
            }
        });
        static::deleting(fn (DataRequest $request) => self::assertCloseoutMutable((int) $request->audit_id));
    }

    private static function assertCloseoutMutable(int $auditId): void
    {
        if (! $auditId) {
            return;
        }

        Audit::query()->whereKey($auditId)->lockForUpdate()->firstOrFail();

        if (AuditCloseoutSubmission::freezesAudit($auditId)) {
            throw new LogicException('Audit data requests are frozen while closeout is pending or approved.');
        }
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
    }

    public function auditItems(): BelongsToMany
    {
        return $this->belongsToMany(AuditItem::class, 'audit_item_data_request');
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(DataRequestResponse::class);
    }

    public function attachments(): HasManyThrough
    {
        return $this->hasManyThrough(FileAttachment::class, DataRequestResponse::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'created_by_id', 'assigned_to_id', 'audit_item_id', 'audit_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
