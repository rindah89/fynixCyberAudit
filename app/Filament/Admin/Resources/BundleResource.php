<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\BundleResource\Pages\ListBundles;
use App\Http\Controllers\BundleController;
use App\Models\Bundle;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class BundleResource extends Resource
{
    protected static ?string $model = Bundle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-on-square-stack';

    protected static ?string $navigationLabel = null;

    protected static string|\UnitEnum|null $navigationGroup = null;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.bundle');
    }

    public static function getNavigationGroup(): string
    {
        return __('navigation.groups.system');
    }

    /**
     * @throws Exception
     */
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Grid::make()
                    ->columns(3)
                    ->schema([
                        TextColumn::make('type')
                            ->state(function (Bundle $record) {
                                return new HtmlString("<h3 class='font-bold text-lg'>$record->type</h3>");
                            })
                            ->badge()
                            ->columnSpanFull()
                            ->color('warning'),
                        TextColumn::make('code')
                            ->label('Code')
                            ->state(function (Bundle $record) {
                                return new HtmlString("<span class='font-bold'>Code: </span><br>$record->code");
                            })
                            ->sortable()
                            ->searchable(),
                        TextColumn::make('version')
                            ->label('Version')
                            ->state(function (Bundle $record) {
                                return new HtmlString("<span class='font-bold'>Rev: </span><br>$record->version");
                            })
                            ->sortable()
                            ->searchable(),
                        TextColumn::make('authority')
                            ->label('Authority')
                            ->state(function (Bundle $record) {
                                return new HtmlString("<span class='font-bold'>Source: </span><br>$record->authority");
                            })
                            ->sortable()
                            ->searchable(),
                        TextColumn::make('name')
                            ->label('Name')
                            ->weight('bold')
                            ->size('lg')
                            ->sortable()
                            ->columnSpanFull()
                            ->searchable(),
                        TextColumn::make('description')
                            ->label('Description')
                            ->limit(200)
                            ->columnSpanFull()
                            ->sortable()
                            ->searchable(),
                    ]),
            ])
            ->contentGrid(['md' => 2, 'xl' => 3])
            ->paginationPageOptions([9, 18, 27])
            ->defaultSort('code')
            ->recordActions([
                ViewAction::make()
                    ->button()
                    ->label('Details'),
                Action::make('Import')
                    ->label(function ($record) {
                        $status = Bundle::where('code', $record->code)->first();
                        if ($status->status == 'imported') {
                            return new HtmlString('Re-Import Bundle');
                        } else {
                            return new HtmlString('Import Bundle');
                        }
                    })
                    ->button()
                    ->requiresConfirmation()
                    ->modalContent(function () {
                        return new HtmlString('
                                <div>This action will import the selected bundle into Fynix Cyber Audit. If you already have
                                content in Fynix Cyber Audit with the same codes, this will overwrite that data.</div>');
                    })
                    ->visible(fn () => auth()->check() && auth()->user()->can('Manage Bundles'))
                    ->modalHeading('Bundle Import')
                    ->modalIconColor('danger')
                    ->action(function (Bundle $record) {
                        Notification::make()
                            ->title('Import Started')
                            ->body("Importing bundle with code: $record->code")
                            ->send();
                        BundleController::importBundle($record);
                    }),
            ])
            ->headerActions([
                Action::make('fetch')
                    ->label('Load local packs')
                    ->button()
                    ->visible(fn () => auth()->check() && auth()->user()->can('Manage Bundles'))
                    ->modalContent(function () {
                        return new HtmlString('
                                <div>This action reloads the content packs shipped with Fynix Cyber Audit. Nothing is downloaded from a remote repository.</div>');
                    })
                    ->modalHeading('Load local packs')
                    ->modalIconColor('danger')
                    ->action(function () {
                        BundleController::retrieve();
                    }),
            ])
            ->filters([
                SelectFilter::make('authority')
                    ->options(Bundle::pluck('authority', 'authority')->toArray())
                    ->label('Authority'),
                SelectFilter::make('type')
                    ->options([
                        'Standard' => 'Standard',
                        'Supplemental' => 'Supplemental',
                    ])
                    ->label('Type'),
            ])
            ->emptyStateHeading(new HtmlString('No Bundles Imported'))
            ->emptyStateDescription(new HtmlString('Load the packs shipped with Fynix Cyber Audit by clicking “Fetch Bundles Updates” above.'));

    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Content Bundle Details')
                    ->columnSpanFull()
                    ->columns(3)
                    ->schema([
                        TextEntry::make('code'),
                        TextEntry::make('version'),
                        TextEntry::make('authority'),
                        TextEntry::make('name')
                            ->columnSpanFull(),
                        TextEntry::make('description')
                            ->columnSpanFull()
                            ->html(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBundles::route('/'),
        ];
    }
}
