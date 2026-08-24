<?php

namespace App\Filament\Resources\SystemAuthorizationPackageResource\RelationManagers;

use App\Enums\SystemAuthorizationMonitoringOutcome;
use App\SystemAuthorization\SystemAuthorizationManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MonitoringReviewsRelationManager extends RelationManager
{
    protected static string $relationship = 'monitoringReviews';

    protected static ?string $title = 'Authorization monitoring';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('version'), TextColumn::make('outcome')->badge(), TextColumn::make('reviewer.name')->label('Reviewer'), TextColumn::make('next_review_at')->date(), TextColumn::make('reviewed_at')->dateTime(), TextColumn::make('fingerprint')->limit(12)->copyable()])->headerActions([Action::make('monitor')->visible(fn (): bool => auth()->user()?->can('monitor', $this->getOwnerRecord()) === true)->schema([TagsInput::make('metrics'), TagsInput::make('findings'), Select::make('outcome')->options(collect(SystemAuthorizationMonitoringOutcome::cases())->mapWithKeys(fn ($o) => [$o->value => $o->getLabel()]))->required(), TagsInput::make('required_actions'), Textarea::make('summary')->required()->maxLength(30000)])->action(fn (array $data) => app(SystemAuthorizationManager::class)->monitor(auth()->user(), $this->getOwnerRecord(), $data))])->recordActions([Action::make('inspect')->modalSubmitAction(false)->modalCancelActionLabel('Close')->modalContent(fn ($record) => view('filament.system-authorization-monitoring', ['record' => $record]))])->defaultSort('version', 'desc');
    }
}
