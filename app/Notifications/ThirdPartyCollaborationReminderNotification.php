<?php

namespace App\Notifications;

use App\Enums\ThirdPartyCollaborationReminderType;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ThirdPartyCollaborationReminderNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly ThirdPartyCollaborationReminderType $type,
        private readonly string $engagementCode,
        private readonly string $subject,
        private readonly string $dueAt,
        private readonly int $requestId,
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
            ->title($this->type === ThirdPartyCollaborationReminderType::Overdue
                ? __('Provider collaboration response overdue')
                : __('Provider collaboration response due soon'))
            ->body(__(':engagement — :subject is due :due.', [
                'engagement' => $this->engagementCode,
                'subject' => $this->subject,
                'due' => $this->dueAt,
            ]))
            ->icon('heroicon-o-chat-bubble-left-right');

        if ($this->type === ThirdPartyCollaborationReminderType::Overdue) {
            $notification->danger();
        } else {
            $notification->warning();
        }

        return $notification->getDatabaseMessage() + [
            'third_party_engagement_collaboration_request_id' => $this->requestId,
            'reminder_type' => $this->type->value,
        ];
    }
}
