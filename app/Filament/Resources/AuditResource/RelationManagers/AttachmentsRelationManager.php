<?php

namespace App\Filament\Resources\AuditResource\RelationManagers;

use App\Access\FileAccess;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('description')
                    ->label('Description')
                    ->columnSpanFull()
                    ->required(),
                FileUpload::make('file_path')
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull()
                    ->label('File')
                    ->required()
                    ->disk(setting('storage.driver', config('filesystems.default')))
                    ->visibility('private')
                    ->storeFileNamesIn('file_name')
                    ->getUploadedFileNameForStorageUsing(fn ($file) => $file->getClientOriginalName())
                    ->deleteUploadedFileUsing(function ($state) {
                        if ($state) {
                            app(FileAccess::class)->deleteUnreferencedFileAttachmentPath(
                                setting('storage.driver', config('filesystems.default')),
                                $state,
                            );
                        }
                    }),
                DateTimePicker::make('updated_at')
                    ->label('Uploaded At')
                    ->default(now())
                    ->required(),
                Select::make('status')
                    ->label('Status')
                    ->options([
                        'Pending' => 'Pending',
                        'Approved' => 'Approved',
                        'Rejected' => 'Rejected',
                    ])
                    ->default('Pending')
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                // Always show "Exported audit evidence ZIP" files first, then "Partial audit evidence export ZIP", then others
                return $query->orderByRaw("CASE
                    WHEN description = 'Exported audit evidence ZIP' THEN 0
                    WHEN description = 'Partial audit evidence export ZIP' THEN 1
                    ELSE 2
                END")
                    ->orderBy('updated_at', 'desc');
            })
            ->columns([
                TextColumn::make('file_name')
                    ->label('File Name')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->description(fn ($record) => $record->description),
                TextColumn::make('updated_at')
                    ->label('Uploaded At')
                    ->searchable()
                    ->sortable()
                    ->dateTime(),
                TextColumn::make('uploaded_by')
                    ->label('Uploaded By')
                    ->getStateUsing(function ($record) {
                        if (in_array($record->description, ['Exported audit evidence ZIP', 'Partial audit evidence export ZIP'])) {
                            return 'System';
                        }
                        $user = User::find($record->uploaded_by);

                        return $user ? $user->name : 'System';
                    }),
            ])
            ->recordClasses(fn ($record) => match ($record->description) {
                'Exported audit evidence ZIP' => 'bg-blue-50 dark:bg-blue-900/20',
                'Partial audit evidence export ZIP' => 'bg-green-50 dark:bg-green-900/20',
                default => null,
            })
            ->filters([])
            ->headerActions([
                ActionGroup::make([
                    Action::make('DownloadDraftReport')
                        ->label('Download Draft Report')
                        ->icon('heroicon-o-document')
                        ->action(function ($record) {
                            $audit = $this->getOwnerRecord();
                            $auditItems = $audit->auditItems;
                            $reportTemplate = 'reports.audit';
                            if ($audit->audit_type == 'implementations') {
                                $reportTemplate = 'reports.implementation-report';
                            }
                            $pdf = Pdf::loadView($reportTemplate, ['audit' => $audit, 'auditItems' => $auditItems]);

                            return response()->streamDownload(
                                fn () => print ($pdf->stream()),
                                "DRAFT-AuditReport-{$audit->id}.pdf"
                            );
                        }),
                    Action::make('DownloadFinalReport')
                        ->label('Download Final Report')
                        ->icon('heroicon-o-document')
                        ->action(function ($record) {
                            $audit = $this->getOwnerRecord();
                            $filepath = "audit_reports/AuditReport-{$audit->id}.pdf";
                            $storage = Storage::disk(config('filesystems.default'));

                            if ($storage->exists($filepath)) {
                                return response()->streamDownload(
                                    fn () => $storage->get($filepath),
                                    "AuditReport-{$audit->id}.pdf"
                                );
                            } else {
                                return Notification::make()
                                    ->title('Error')
                                    ->body('The final audit report is not available until the audit has been completed.')
                                    ->danger()
                                    ->send();
                            }
                        }),
                ])->label('Report Downloads'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('View')
                    ->icon('heroicon-o-eye'),
            ]);
    }
}
