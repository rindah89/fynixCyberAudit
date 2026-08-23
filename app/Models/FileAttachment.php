<?php

namespace App\Models;

use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Class FileAttachment
 *
 * @property int $id
 * @property string $file_name
 * @property string $file_path
 * @property int $file_size
 * @property Carbon $uploaded_at
 * @property int $uploaded_by
 * @property int $audit_id
 * @property int $data_request_response_id
 *
 * @method static Builder|FileAttachment newModelQuery()
 * @method static Builder|FileAttachment newQuery()
 * @method static Builder|FileAttachment query()
 * @method static Builder|FileAttachment whereFileName($value)
 * @method static Builder|FileAttachment whereFilePath($value)
 * @method static Builder|FileAttachment whereFileSize($value)
 * @method static Builder|FileAttachment whereUploadedAt($value)
 * @method static Builder|FileAttachment whereUploadedBy($value)
 * @method static Builder|FileAttachment whereAuditId($value)
 * @method static Builder|FileAttachment whereDataRequestResponseId($value)
 *
 * @mixin Eloquent
 */
class FileAttachment extends Model
{
    use LogsActivity;

    protected static function booted(): void
    {
        static::updating(function (FileAttachment $attachment): void {
            if ($attachment->isDirty(['file_path', 'file_name', 'file_size', 'audit_id', 'data_request_response_id'])
                && $attachment->hasGovernedEvidenceReferences()) {
                throw new LogicException('Files referenced by governed evidence cannot change identity through product interfaces.');
            }
        });
        static::deleting(function (FileAttachment $attachment): void {
            if ($attachment->hasGovernedEvidenceReferences()) {
                throw new LogicException('Files referenced by governed evidence cannot be deleted through product interfaces.');
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'file_name',
        'file_path',
        'file_size',
        'description',
        'uploaded_at',
        'uploaded_by',
        'audit_id',
        'data_request_response_id',
    ];

    public function dataRequestResponse(): BelongsTo
    {
        return $this->belongsTo(DataRequestResponse::class);
    }

    public function closureEvidence(): HasMany
    {
        return $this->hasMany(GovernanceIssueClosureEvidence::class);
    }

    public function controlTestEvidence(): HasMany
    {
        return $this->hasMany(ControlTestExecutionEvidence::class);
    }

    public function aiMonitoringEvidence(): HasMany
    {
        return $this->hasMany(AiMonitoringReviewEvidence::class);
    }

    public function vendorRiskReviewEvidence(): HasMany
    {
        return $this->hasMany(VendorRiskReviewEvidence::class);
    }

    public function hasGovernedEvidenceReferences(): bool
    {
        return $this->closureEvidence()->exists()
            || $this->controlTestEvidence()->exists()
            || $this->aiMonitoringEvidence()->exists()
            || $this->vendorRiskReviewEvidence()->exists();
    }

    public function auditItem(): BelongsTo
    {
        return $this->belongsTo(AuditItem::class);
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['file_name', 'file_size', 'uploaded_by', 'data_request_id', 'audit_id', 'data_request_response_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function scopeEligibleGovernedEvidenceFor(Builder $query, User $user): Builder
    {
        return $query
            ->whereHas('dataRequestResponse', fn (Builder $response) => $response->where('status', 'Accepted'))
            ->where(function (Builder $access) use ($user): void {
                $access->where('uploaded_by', $user->id)
                    ->orWhereHas('audit', fn (Builder $audit) => $audit
                        ->where('manager_id', $user->id)
                        ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $user->id)))
                    ->orWhereHas('dataRequestResponse', fn (Builder $response) => $response
                        ->where('requestee_id', $user->id)
                        ->orWhereHas('dataRequest', fn (Builder $request) => $request
                            ->where('created_by_id', $user->id)
                            ->orWhere('assigned_to_id', $user->id)
                            ->orWhereHas('audit', fn (Builder $audit) => $audit
                                ->where('manager_id', $user->id)
                                ->orWhereHas('members', fn (Builder $members) => $members->where('users.id', $user->id)))));
            });
    }
}
