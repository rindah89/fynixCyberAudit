<?php

namespace App\Notifications;

use App\Enums\PolicyAcknowledgementReminderType;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class PolicyAcknowledgementReminderNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly PolicyAcknowledgementReminderType $type,
        private readonly string $campaignTitle,
        private readonly string $policyCode,
        private readonly string $dueAt,
        private readonly int $assignmentId,
    ) {
        $this->id = $notificationId;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->type === PolicyAcknowledgementReminderType::Overdue
                ? __('Policy acknowledgement overdue')
                : __('Policy acknowledgement due soon'))
            ->body(__(':campaign for policy :policy is due :due.', [
                'campaign' => $this->campaignTitle,
                'policy' => $this->policyCode,
                'due' => $this->dueAt,
            ]))
            ->icon('heroicon-o-clock');

        if ($this->type === PolicyAcknowledgementReminderType::Overdue) {
            $notification->danger();
        } else {
            $notification->warning();
        }

        return $notification->getDatabaseMessage() + [
            'policy_acknowledgement_assignment_id' => $this->assignmentId,
            'reminder_type' => $this->type->value,
        ];
    }
}
