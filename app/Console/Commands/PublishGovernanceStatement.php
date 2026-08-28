<?php

namespace App\Console\Commands;

use App\Suite\GovernanceStatementPublisher;
use Illuminate\Console\Command;

class PublishGovernanceStatement extends Command
{
    protected $signature = 'fynix:publish-governance';

    protected $description = 'Publish Cyber Audit data-governance evidence to the configured oversight receiver';

    public function handle(GovernanceStatementPublisher $publisher): int
    {
        $receipt = $publisher->publish();
        $this->line(json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
