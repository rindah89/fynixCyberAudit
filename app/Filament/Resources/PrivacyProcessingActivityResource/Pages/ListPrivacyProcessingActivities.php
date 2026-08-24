<?php

namespace App\Filament\Resources\PrivacyProcessingActivityResource\Pages;

use App\Filament\Resources\PrivacyProcessingActivityResource;
use App\Models\PrivacyProcessingActivity;
use App\Models\User;
use App\Privacy\PrivacyManagementManager;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\ListRecords;

class ListPrivacyProcessingActivities extends ListRecords
{
    protected static string $resource = PrivacyProcessingActivityResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('register')->label('Register processing activity')->icon('heroicon-o-plus')->visible(fn (): bool => auth()->user()?->can('create', PrivacyProcessingActivity::class) === true)->schema(self::activitySchema())->action(fn (array $data) => app(PrivacyManagementManager::class)->register(auth()->user(), $data))];
    }

    public static function activitySchema(): array
    {
        return [
            TextInput::make('name')->required()->maxLength(255), Select::make('owner_id')->label('Accountable owner')->required()->searchable()->options(fn (): array => User::permission(['Own Privacy Activities', 'Manage Privacy'])->whereNull('deleted_at')->orderBy('name')->pluck('name', 'id')->all()),
            Textarea::make('purpose')->required()->maxLength(30000)->columnSpanFull(), TextInput::make('lawful_basis')->required()->maxLength(255), TextInput::make('retention_period')->required()->maxLength(255),
            TagsInput::make('data_subject_categories')->required(), TagsInput::make('personal_data_categories')->required(), TagsInput::make('recipient_categories'), TagsInput::make('systems_and_vendors')->required(), TagsInput::make('processing_locations')->required(),
            Toggle::make('special_category_data'), Toggle::make('cross_border_transfer'), Textarea::make('transfer_safeguards')->maxLength(30000)->columnSpanFull(),
            Textarea::make('security_measures')->required()->maxLength(30000)->columnSpanFull(), Textarea::make('source_reference')->maxLength(2000)->columnSpanFull(), DatePicker::make('next_review_at')->required()->minDate(today()),
            Textarea::make('change_summary')->label('Registration rationale')->required()->maxLength(30000)->columnSpanFull(),
        ];
    }
}
