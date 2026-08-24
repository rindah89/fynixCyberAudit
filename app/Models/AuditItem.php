<?php

namespace App\Models;

use App\Enums\Applicability;
use App\Enums\Effectiveness;
use App\Enums\WorkflowStatus;
use App\Mcp\Traits\HasMcpSupport;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class AuditItem extends Model
{
    use HasFactory, HasMcpSupport, LogsActivity;

    protected $fillable = ['audit_id', 'user_id', 'auditable_id', 'auditable_type', 'auditor_notes', 'status', 'effectiveness', 'applicability'];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'integer',
        'applicability' => Applicability::class,
        'status' => WorkflowStatus::class,
        'effectiveness' => Effectiveness::class,
    ];

    protected static function booted(): void
    {
        static::creating(fn (AuditItem $item) => self::assertCloseoutMutable((int) $item->audit_id));
        static::updating(function (AuditItem $item): void {
            self::assertCloseoutMutable((int) $item->getRawOriginal('audit_id'));
            if ((int) $item->audit_id !== (int) $item->getRawOriginal('audit_id')) {
                self::assertCloseoutMutable((int) $item->audit_id);
            }
        });
        static::deleting(fn (AuditItem $item) => self::assertCloseoutMutable((int) $item->audit_id));
    }

    private static function assertCloseoutMutable(int $auditId): void
    {
        if (! $auditId) {
            return;
        }

        Audit::query()->whereKey($auditId)->lockForUpdate()->firstOrFail();

        if (AuditCloseoutSubmission::freezesAudit($auditId)) {
            throw new LogicException('Audit fieldwork is frozen while closeout is pending or approved.');
        }
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(Control::class, 'control_id');
    }

    public function implementation(): BelongsTo
    {
        return $this->belongsTo(Implementation::class, 'implementation_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function dataRequests(): HasMany
    {
        return $this->hasMany(DataRequest::class);
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(AuditProcedure::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['audit_id', 'user_id', 'auditable_id', 'auditable_type', 'status', 'effectiveness', 'applicability'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
