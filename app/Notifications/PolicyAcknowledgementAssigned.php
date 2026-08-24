<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class PolicyAcknowledgementAssigned extends Notification
{
    public function __construct(
        string $notificationId,
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
        return FilamentNotification::make()
            ->title(__('Policy acknowledgement assigned'))
            ->body(__(':campaign for policy :policy is due :due.', [
                'campaign' => $this->campaignTitle,
                'policy' => $this->policyCode,
                'due' => $this->dueAt,
            ]))
            ->icon('heroicon-o-document-check')
            ->warning()
            ->getDatabaseMessage() + ['policy_acknowledgement_assignment_id' => $this->assignmentId];
    }
}
