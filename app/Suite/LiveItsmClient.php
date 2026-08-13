<?php

namespace App\Suite;

use Illuminate\Support\Facades\Http;

class LiveItsmClient implements ItsmClient
{
    private function client()
    {
        return Http::baseUrl(rtrim((string) config('suite.itsm.base_url'), '/').'/api/v1')
            ->withToken((string) config('suite.itsm.token'))
            ->acceptJson()->asJson()->timeout(15)->retry(2, 250);
    }

    public function createTicket(array $payload): array
    {
        return $this->client()->post('/tickets', $payload)->throw()->json();
    }

    public function getTicket(int $id): array
    {
        return $this->client()->get('/tickets/'.$id)->throw()->json();
    }

    public function findTickets(array $filters): array
    {
        return $this->client()->get('/tickets', $filters)->throw()->json();
    }

    public function createNote(int $ticketId, string $text): void
    {
        $this->client()->post('/tickets/'.$ticketId.'/notes', ['text' => $text, 'is_internal' => true])->throw();
    }
}
