<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ThirdPartyCollaborationEscalationNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly string $engagementCode,
        private readonly string $subject,
        private readonly string $vendorRecipientName,
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
        return FilamentNotification::make()
            ->title(__('Provider collaboration response remains overdue'))
            ->body(__(':engagement — :subject, assigned to :recipient, was due :due.', [
                'engagement' => $this->engagementCode,
                'subject' => $this->subject,
                'recipient' => $this->vendorRecipientName,
                'due' => $this->dueAt,
            ]))
            ->icon('heroicon-o-exclamation-triangle')
            ->danger()
            ->getDatabaseMessage() + [
                'third_party_engagement_collaboration_request_id' => $this->requestId,
                'escalation_type' => 'persistently_overdue',
            ];
    }
}
