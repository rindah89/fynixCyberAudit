<?php

namespace App\Models;

use Aliziodev\LaravelTaxonomy\Traits\HasTaxonomy;
use App\Enums\AuditCloseoutDecision;
use App\Enums\WorkflowStatus;
use App\Mcp\Traits\HasMcpSupport;
use Eloquent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Class Audit
 *
 * @property int $id
 * @property string $title
 * @property string $description
 * @property WorkflowStatus $status
 * @property Carbon|null $start_date
 * @property Carbon|null $end_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection|AuditItem[] $auditItems
 * @property-read int|null $auditItems_count
 * @property-read User $manager
 * @property-read Collection|DataRequest[] $dataRequest
 * @property-read int|null $dataRequest_count
 * @property-read Collection|FileAttachment[] $attachments
 * @property-read int|null $attachments_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Audit newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|Audit newQuery()
 * @method static Builder|Audit onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder|Audit query()
 * @method static \Illuminate\Database\Eloquent\Builder|Audit whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Audit whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Audit whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Audit whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder|Audit whereUpdatedAt($value)
 * @method static Builder|Audit withTrashed()
 * @method static Builder|Audit withoutTrashed()
 *
 * @mixin Eloquent
 */
class Audit extends Model
{
    use HasFactory, HasMcpSupport, HasTaxonomy, LogsActivity;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'title',
        'description',
        'status',
        'audit_type',
        'start_date',
        'end_date',
        'program_id',
        'manager_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'controls' => 'array',
        'status' => WorkflowStatus::class,
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    protected static function booted(): void
    {
        static::updating(function (Audit $audit): void {
            Audit::query()->whereKey($audit->id)->lockForUpdate()->firstOrFail();
            $closeoutFrozen = AuditCloseoutSubmission::freezesAudit($audit->id);
            if ($closeoutFrozen && $audit->isDirty(['title', 'description', 'audit_type', 'start_date', 'end_date', 'manager_id', 'program_id'])) {
                throw new LogicException('Audit scope and accountability are frozen while closeout is pending or approved.');
            }
            if ($closeoutFrozen && $audit->isDirty('status') && $audit->status !== WorkflowStatus::COMPLETED) {
                throw new LogicException('Audit status is frozen while closeout is pending or approved.');
            }
            if (! $audit->isDirty('status') || ! $audit->engagementBaseline()->exists()) {
                return;
            }
            $approved = AuditCloseoutReview::query()
                ->where('decision', AuditCloseoutDecision::Approved)
                ->whereHas('submission', fn ($query) => $query->where('audit_id', $audit->id))
                ->exists();
            if ($audit->status === WorkflowStatus::COMPLETED && ! $approved) {
                throw new LogicException('Governed plan engagements require independent closeout approval before completion.');
            }
            if ($audit->getRawOriginal('status') === WorkflowStatus::COMPLETED->value && $approved) {
                throw new LogicException('An independently approved audit closeout cannot be reopened through ordinary audit maintenance.');
            }
        });
        static::deleting(function (Audit $audit): void {
            if ($audit->engagementBaseline()->exists() || $audit->closeoutSubmissions()->exists()) {
                throw new LogicException('Audits with governed planning or closeout evidence cannot be deleted.');
            }
        });
    }

    /**
     * Get the audit items for the audit.
     */
    public function auditItems(): HasMany
    {
        return $this->hasMany(AuditItem::class);
    }

    public function engagementBaseline(): HasOne
    {
        return $this->hasOne(AuditEngagementBaseline::class);
    }

    public function closeoutSubmissions(): HasMany
    {
        return $this->hasMany(AuditCloseoutSubmission::class);
    }

    public function procedures(): HasMany
    {
        return $this->hasMany(AuditProcedure::class);
    }

    public function latestCloseoutSubmission(): HasOne
    {
        return $this->hasOne(AuditCloseoutSubmission::class)->latestOfMany('version');
    }

    /**
     * Get the manager that owns the audit.
     */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Get the members that are part of the audit
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Get the data requests for the audit.
     */
    public function dataRequest(): HasMany
    {
        return $this->hasMany(DataRequest::class);
    }

    /**
     * Get the file attachments for the audit through data requests and responses.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(FileAttachment::class);
    }

    /**
     * Get the standard that owns the audit.
     */
    public function standard(): BelongsTo
    {
        return $this->belongsTo(Standard::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'status', 'start_date', 'end_date', 'manager_id', 'program_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
