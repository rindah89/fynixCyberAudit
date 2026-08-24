<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ThirdPartyEngagementCollaborationEvidence extends Model
{
    protected $table = 'third_party_engagement_collaboration_evidence';

    protected $fillable = ['third_party_engagement_collaboration_event_id', 'vendor_document_id', 'vendor_id_snapshot', 'linked_by_vendor_user_id', 'document_status_snapshot', 'disk_snapshot', 'file_name_snapshot', 'file_path_snapshot', 'file_size_snapshot', 'sha256', 'linked_at'];

    protected $casts = ['file_size_snapshot' => 'integer', 'linked_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Collaboration evidence is append-only.'));
        static::deleting(fn () => throw new LogicException('Collaboration evidence is retained governance history.'));
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(ThirdPartyEngagementCollaborationEvent::class, 'third_party_engagement_collaboration_event_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(VendorDocument::class, 'vendor_document_id')->withTrashed();
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(VendorUser::class, 'linked_by_vendor_user_id')->withTrashed();
    }

    public function currentDocument(): ?VendorDocument
    {
        $document = $this->getRelationValue('document');

        return $document instanceof VendorDocument ? $document : null;
    }
}
