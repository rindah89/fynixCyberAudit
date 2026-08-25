<?php

namespace App\Filament\Resources\ComplianceCaseResource\RelationManagers;

use App\ComplianceCases\ComplianceCaseLegalHoldManager;
use App\Enums\ComplianceCaseStatus;
use App\Models\ComplianceCaseLegalHold;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LegalHoldsRelationManager extends RelationManager
{
    protected static string $relationship = 'legalHolds';

    protected static ?string $title = 'Governed legal holds';

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn ($query) => $query->with(app(ComplianceCaseLegalHoldManager::class)->relations())
            ->withCount(['custodians', 'custodians as acknowledged_custodians_count' => fn ($query) => $query->has('acknowledgement')]))
            ->columns([
                TextColumn::make('version')->sortable(), TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('release.id')->label('Status')->formatStateUsing(fn ($state): string => $state ? __('Released') : __('Active'))->badge()
                    ->color(fn ($state): string => $state ? 'success' : 'warning'),
                TextColumn::make('issuer.name')->label('Issued by')->searchable(),
                TextColumn::make('acknowledged_custodians_count')->label('Acknowledged'),
                TextColumn::make('custodians_count')->label('Custodians'),
                TextColumn::make('issued_at')->dateTime()->sortable(), TextColumn::make('fingerprint')->limit(12)->copyable(),
            ])->headerActions([
                Action::make('issue')->label('Issue legal hold')->icon('heroicon-o-lock-closed')
                    ->visible(fn (): bool => $this->canManage() && $this->getOwnerRecord()->status !== ComplianceCaseStatus::Closed)
                    ->schema([
                        Textarea::make('scope')->required()->maxLength(30000)->columnSpanFull(),
                        TagsInput::make('systems')->required(),
                        TagsInput::make('data_categories')->required(),
                        TextInput::make('legal_basis_reference')->maxLength(1000),
                        DateTimePicker::make('preservation_start_at')->required()->maxDate(now()),
                        Select::make('custodian_ids')->label('Internal custodians')->multiple()->searchable()
                            ->options(User::activeOptions())->required()->maxItems(100),
                    ])->action(fn (array $data) => app(ComplianceCaseLegalHoldManager::class)->issue(auth()->user(), $this->getOwnerRecord(), $data)),
            ])->recordActions([
                Action::make('release')->icon('heroicon-o-lock-open')->color('danger')->requiresConfirmation()
                    ->visible(fn (ComplianceCaseLegalHold $record): bool => $this->canManage() && $record->release === null)
                    ->schema([Textarea::make('summary')->required()->maxLength(30000)->columnSpanFull()])
                    ->action(fn (ComplianceCaseLegalHold $record, array $data) => app(ComplianceCaseLegalHoldManager::class)
                        ->release(auth()->user(), $this->getOwnerRecord(), $record, $data)),
                Action::make('inspect')->icon('heroicon-o-eye')->modalSubmitAction(false)->modalCancelActionLabel('Close')
                    ->modalContent(fn (ComplianceCaseLegalHold $record) => view('filament.compliance-case-legal-hold', [
                        'record' => $record->load(app(ComplianceCaseLegalHoldManager::class)->relations()),
                    ])),
            ])->defaultSort('version', 'desc');
    }

    private function canManage(): bool
    {
        return auth()->user()?->can('Manage Compliance Cases') === true
            && auth()->user()?->can('update', $this->getOwnerRecord()) === true;
    }
}
