<?php

namespace App\Filament\Exports;

use App\Models\PolicyAcknowledgementAssignment;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class PolicyAcknowledgementAssignmentExporter extends Exporter
{
    protected static ?string $model = PolicyAcknowledgementAssignment::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('campaign.policy.code')->label('Policy Code'),
            ExportColumn::make('campaign.policy.name')->label('Policy Name'),
            ExportColumn::make('campaign.version')->label('Campaign Version'),
            ExportColumn::make('campaign.title')->label('Campaign'),
            ExportColumn::make('user.name')->label('Assigned User'),
            ExportColumn::make('user.email')->label('Assigned Email'),
            ExportColumn::make('acknowledgement_status')->label('Status'),
            ExportColumn::make('assigned_at'),
            ExportColumn::make('delivery.channel')->label('Notification Channel'),
            ExportColumn::make('delivery.notification_id')->label('Notification ID'),
            ExportColumn::make('delivery.attempted_at')->label('Notification Attempted At'),
            ExportColumn::make('delivery.delivered_at')->label('Notification Delivered At'),
            ExportColumn::make('delivery.fingerprint')->label('Delivery Fingerprint'),
            ExportColumn::make('delivery.recipient_snapshot')->label('Delivery Recipient Snapshot JSON')
                ->formatStateUsing(fn ($state): ?string => $state ? json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null),
            ExportColumn::make('delivery.campaign_snapshot')->label('Delivery Campaign Snapshot JSON')
                ->formatStateUsing(fn ($state): ?string => $state ? json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null),
            ExportColumn::make('reminder_history')->label('Reminder History JSON')
                ->state(fn (PolicyAcknowledgementAssignment $record): string => json_encode(
                    $record->reminders->sortBy('delivered_at')->map(fn ($reminder): array => [
                        'type' => $reminder->type->value,
                        'channel' => $reminder->channel,
                        'notification_id' => $reminder->notification_id,
                        'recipient_snapshot' => $reminder->recipient_snapshot,
                        'campaign_snapshot' => $reminder->campaign_snapshot,
                        'attempted_at' => $reminder->attempted_at?->toISOString(),
                        'delivered_at' => $reminder->delivered_at?->toISOString(),
                        'fingerprint' => $reminder->fingerprint,
                    ])->values()->all(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
            ExportColumn::make('escalation_fingerprint')->label('Escalation Fingerprint')
                ->state(fn (PolicyAcknowledgementAssignment $record): ?string => $record->escalation?->fingerprint),
            ExportColumn::make('escalation_snapshot')->label('Escalation Snapshot JSON')
                ->state(fn (PolicyAcknowledgementAssignment $record): ?string => $record->escalation ? json_encode([
                    'assigned_user' => $record->escalation->assignment_snapshot,
                    'recipient' => $record->escalation->recipient_snapshot,
                    'campaign' => $record->escalation->campaign_snapshot,
                    'notification_id' => $record->escalation->notification_id,
                    'delivered_at' => $record->escalation->delivered_at?->toISOString(),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null),
            ExportColumn::make('knowledge_check_attempt_history')->label('Comprehension Check Attempts JSON')
                ->state(fn (PolicyAcknowledgementAssignment $record): string => json_encode(
                    $record->knowledgeCheckAttempts->sortBy('version')->map(fn ($attempt): array => [
                        'version' => $attempt->version, 'submitted_by' => $attempt->submitted_by,
                        'answers_snapshot' => $attempt->answers_snapshot, 'score_percentage' => $attempt->score_percentage,
                        'question_snapshot' => $attempt->question_snapshot,
                        'passed' => $attempt->passed, 'submitted_at' => $attempt->submitted_at?->toISOString(),
                        'fingerprint' => $attempt->fingerprint,
                    ])->values()->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )),
            ExportColumn::make('campaign.due_at')->label('Due At'),
            ExportColumn::make('acknowledgement.acknowledged_at')->label('Acknowledged At'),
            ExportColumn::make('acknowledgement.statement')->label('Statement'),
            ExportColumn::make('acknowledgement.comment')->label('Comment'),
            ExportColumn::make('acknowledgement.client_reference')->label('Client Reference'),
            ExportColumn::make('campaign.policy_fingerprint')->label('Policy Fingerprint'),
            ExportColumn::make('campaign.policy_snapshot')->label('Policy Snapshot JSON')
                ->formatStateUsing(fn ($state): string => json_encode($state, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['campaign.policy:id,code,name', 'user:id,name,email', 'delivery', 'reminders', 'escalation', 'knowledgeCheckAttempts', 'acknowledgement']);
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        return 'Your policy acknowledgement export completed with '.number_format($export->successful_rows).' rows.';
    }

    public function getFileDisk(): string
    {
        return setting('storage.driver', 'private');
    }
}
