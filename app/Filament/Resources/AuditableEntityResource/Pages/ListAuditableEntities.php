<?php

namespace App\Filament\Resources\AuditableEntityResource\Pages;

use App\Filament\Resources\AuditableEntityResource;
use Filament\Resources\Pages\ListRecords;

class ListAuditableEntities extends ListRecords
{
    protected static string $resource = AuditableEntityResource::class;
}
