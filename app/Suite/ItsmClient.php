<?php

namespace App\Suite;

interface ItsmClient
{
    public function createTicket(array $payload): array;
    public function getTicket(int $id): array;
    public function findTickets(array $filters): array;
    public function createNote(int $ticketId, string $text): void;
}
