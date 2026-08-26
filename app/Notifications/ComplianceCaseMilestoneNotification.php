<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ComplianceCaseMilestoneNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly int $milestoneId,
        private readonly string $title,
        private readonly string $eventType,
        private readonly string $dueAt,
    ) {
        $this->id = $notificationId;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toDatabase(object $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->title($this->eventType === 'overdue' ? __('Compliance-case milestone overdue') : __('Compliance-case milestone due soon'))
            ->body(__(':title is due :due.', ['title' => $this->title, 'due' => $this->dueAt]))
            ->icon('heroicon-o-clock');
        $this->eventType === 'overdue' ? $notification->danger() : $notification->warning();

        return $notification->getDatabaseMessage() + [
            'compliance_case_milestone_id' => $this->milestoneId,
            'milestone_event_type' => $this->eventType,
        ];
    }
}
