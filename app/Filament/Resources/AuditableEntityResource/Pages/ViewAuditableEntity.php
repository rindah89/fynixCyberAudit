<?php

namespace App\Filament\Resources\AuditableEntityResource\Pages;

use App\Filament\Resources\AuditableEntityResource;
use Filament\Resources\Pages\ViewRecord;

class ViewAuditableEntity extends ViewRecord
{
    protected static string $resource = AuditableEntityResource::class;
}
