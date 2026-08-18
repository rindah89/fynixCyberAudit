<?php

namespace App\ChangeEvidence;

use Symfony\Component\HttpKernel\Exception\HttpException;

class EvidenceAuthorizationDenied extends HttpException
{
    public function __construct(public readonly string $reasonCode, public readonly array $auditContext, int $status, string $message = '')
    {
        parent::__construct($status, $message);
    }
}
