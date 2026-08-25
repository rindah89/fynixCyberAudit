<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ThirdPartyCollaborationClosureAcknowledgedNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly string $engagementCode,
        private readonly string $subject,
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
            ->title(__('Provider acknowledged collaboration closure'))
            ->body(__(':engagement — :subject closure was acknowledged in the provider portal.', [
                'engagement' => $this->engagementCode,
                'subject' => $this->subject,
            ]))
            ->icon('heroicon-o-check-badge')
            ->success()
            ->getDatabaseMessage() + [
                'third_party_engagement_collaboration_request_id' => $this->requestId,
                'notification_type' => 'collaboration_closure_acknowledgement',
            ];
    }
}
