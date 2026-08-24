<?php

namespace App\Filament\Vendor\Resources\CollaborationRequestResource\Pages;

use App\Filament\Vendor\Resources\CollaborationRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCollaborationRequests extends ListRecords
{
    protected static string $resource = CollaborationRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
