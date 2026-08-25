<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class ThirdPartyCollaborationClosureNotification extends Notification
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
            ->title(__('Provider collaboration request closed'))
            ->body(__(':engagement — :subject has been administratively closed in Fynix.', [
                'engagement' => $this->engagementCode,
                'subject' => $this->subject,
            ]))
            ->icon('heroicon-o-check-circle')
            ->success()
            ->getDatabaseMessage() + [
                'third_party_engagement_collaboration_request_id' => $this->requestId,
                'notification_type' => 'collaboration_closure',
            ];
    }
}
