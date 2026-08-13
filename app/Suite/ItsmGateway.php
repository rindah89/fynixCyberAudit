<?php

namespace App\Suite;

use App\Models\SuiteEntityLink;
use InvalidArgumentException;

class ItsmGateway
{
    public function __construct(private readonly LiveItsmClient $client) {}

    public function enabled(): bool
    {
        return (bool) config('suite.itsm.enabled') && $this->missingConfiguration() === [];
    }

    public function missingConfiguration(): array
    {
        $required = ['base_url', 'token', 'company_id', 'origin_id', 'ticket_type_id', 'department_id', 'priority_id', 'sync_analyst_id', 'requester_email', 'public_url', 'grc_public_url', 'webhook_id', 'webhook_secret'];
        return array_values(array_filter($required, fn ($key) => blank(config('suite.itsm.'.$key))));
    }

    public function openTicket(string $localType, int $localId, string $subject, string $entityType, string $entityUrl, ?string $note = null): SuiteEntityLink
    {
        if (! $this->enabled()) throw new InvalidArgumentException('ITSM integration is not fully configured.');
        $existing = SuiteEntityLink::query()->where('local_type', $localType)->where('local_id', $localId)->where('system', 'itsm')->where('entity_type', 'ticket')->first();
        if ($existing) return $existing;

        $matches = $this->client->findTickets(['grc_entity_type' => $entityType, 'grc_entity_id' => (string) $localId]);
        $ticket = data_get($matches, 'data.0');
        if (! is_array($ticket)) $ticket = $this->client->createTicket([
            'subject' => $subject,
            'requester_email' => config('suite.itsm.requester_email'),
            'requester_name' => 'Cyber Audit Integration',
            'company_id' => (int) config('suite.itsm.company_id'),
            'origin_id' => (int) config('suite.itsm.origin_id'),
            'ticket_type_id' => (int) config('suite.itsm.ticket_type_id'),
            'department_id' => (int) config('suite.itsm.department_id'),
            'priority_id' => (int) config('suite.itsm.priority_id'),
            'grc_entity_type' => $entityType,
            'grc_entity_id' => (string) $localId,
            'grc_entity_url' => $entityUrl,
        ]);
        $id = (int) ($ticket['id'] ?? data_get($ticket, 'data.id', 0));
        if ($id < 1) throw new InvalidArgumentException('ITSM did not return a ticket id.');
        if (filled($note)) $this->client->createNote($id, (string) $note);

        return SuiteEntityLink::query()->create([
            'local_type' => $localType, 'local_id' => $localId, 'system' => 'itsm',
            'entity_type' => 'ticket', 'entity_id' => (string) $id, 'relation' => 'remediation',
            'remote_status' => data_get($ticket, 'status.name', data_get($ticket, 'data.status.name', 'open')),
            'remote_url' => rtrim((string) config('suite.itsm.public_url'), '/').'/tickets/?ticket_id='.$id,
        ]);
    }

    public function applyEvent(array $envelope): string
    {
        if (! str_starts_with((string) ($envelope['event_type'] ?? ''), 'itsm.ticket.')) return 'ignored';
        $ticketId = (string) data_get($envelope, 'payload.ticket_id', '');
        $link = SuiteEntityLink::query()->where('system', 'itsm')->where('entity_type', 'ticket')->where('entity_id', $ticketId)->first();
        if (! $link) return 'ignored';
        $link->update(['remote_status' => data_get($envelope, 'payload.status_is_closed') ? 'closed' : 'open', 'meta' => array_merge($link->meta ?? [], ['assigned_analyst_id' => data_get($envelope, 'payload.assigned_analyst_id')])]);
        return 'applied';
    }
}
