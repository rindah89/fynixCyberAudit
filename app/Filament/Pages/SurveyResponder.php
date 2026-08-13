<?php

namespace App\Filament\Pages;

use App\Support\Enterprise;
use Filament\Pages\Page;

class SurveyResponder extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationLabel = 'Survey Responder';

    protected static string|\UnitEnum|null $navigationGroup = 'Apps';

    protected static ?string $slug = 'survey-responder';

    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.pages.survey-responder';

    public static function shouldRegisterNavigation(): bool
    {
        return Enterprise::enabled('surveyor');
    }

    public static function canAccess(): bool
    {
        return Enterprise::enabled('surveyor')
            && auth()->user()?->can('Manage Surveyor');
    }
}
