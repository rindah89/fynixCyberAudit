<?php

namespace App\Filament\Resources\PrivacyRightsRequestResource\Pages;

use App\Enums\PrivacyRightsRequestType;
use App\Filament\Resources\PrivacyRightsRequestResource;
use App\Models\PrivacyRightsRequest;
use App\Models\User;
use App\Privacy\PrivacyRightsRequestManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListPrivacyRightsRequests extends ListRecords
{
    protected static string $resource = PrivacyRightsRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('open_request')->label('Record rights request')->icon('heroicon-o-plus')
            ->visible(fn (): bool => auth()->user()?->can('create', PrivacyRightsRequest::class) === true)
            ->schema([
                Select::make('request_type')->options(PrivacyRightsRequestType::class)->required(), TextInput::make('data_subject_name')->required()->maxLength(255),
                TextInput::make('data_subject_email')->email()->maxLength(255), TextInput::make('subject_reference')->maxLength(255),
                Textarea::make('request_details')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('intake_channel')->required()->maxLength(255),
                Textarea::make('jurisdiction_reference')->maxLength(2000)->columnSpanFull(), DateTimePicker::make('received_at')->required()->default(now()),
                DateTimePicker::make('due_at')->required(), Select::make('assigned_to')->label('Handler')->required()->searchable()
                    ->options(fn (): array => User::permission(['Handle Privacy Rights', 'Manage Privacy Rights'])->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all()),
            ])->action(fn (array $data) => app(PrivacyRightsRequestManager::class)->open(auth()->user(), $data))];
    }
}
