<?php

namespace App\Models;

use App\Enums\RegulatorySourceStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RegulatorySource extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['code', 'title', 'authority', 'jurisdiction', 'reference_url', 'owner_id', 'status', 'created_by', 'updated_by'];

    protected $casts = ['status' => RegulatorySourceStatus::class];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by')->withTrashed();
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(RegulatoryRequirement::class);
    }
}
