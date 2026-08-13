<?php

namespace App\Filament\Admin\Pages\Settings;

use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class Settings extends BaseSettings
{
    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 1;

    public static function canAccess(): bool
    {
        if (auth()->check() && auth()->user()->can('Manage Preferences')) {
            return true;
        }

        return false;
    }

    public static function getNavigationGroup(): string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.settings.general_settings');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General Configuration')
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('general.name')
                            ->default('ets')
                            ->minLength(2)
                            ->maxLength(16)
                            ->label('Application Name')
                            ->helperText('The name of your application')
                            ->required(),
                        TextInput::make('general.url')
                            ->default('http://localhost')
                            ->url()
                            ->label('Application URL')
                            ->helperText('The URL of your application')
                            ->required(),
                    ]),
            ]);
    }
}
