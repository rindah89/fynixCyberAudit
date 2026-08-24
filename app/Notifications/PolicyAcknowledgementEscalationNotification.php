<?php

namespace App\Notifications;

use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;

class PolicyAcknowledgementEscalationNotification extends Notification
{
    public function __construct(
        string $notificationId,
        private readonly string $campaignTitle,
        private readonly string $policyCode,
        private readonly string $assignedUserName,
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
            ->title(__('Policy acknowledgement requires management attention'))
            ->body(__(':user has not acknowledged :campaign for policy :policy, due :due.', [
                'user' => $this->assignedUserName, 'campaign' => $this->campaignTitle,
                'policy' => $this->policyCode, 'due' => $this->dueAt,
            ]))
            ->icon('heroicon-o-exclamation-triangle')->danger()->getDatabaseMessage() + [
                'policy_acknowledgement_assignment_id' => $this->assignmentId,
                'escalation_type' => 'policy_owner',
            ];
    }
}
