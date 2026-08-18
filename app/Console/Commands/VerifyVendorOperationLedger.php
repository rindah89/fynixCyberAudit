<?php

namespace App\Console\Commands;

use App\Suite\VendorOperationLedger;
use Illuminate\Console\Command;

class VerifyVendorOperationLedger extends Command
{
    protected $signature = 'fynix:vendor-ledger-verify';

    protected $description = 'Verify the complete vendor operation audit ledger and persist its status';

    public function handle(VendorOperationLedger $ledger): int
    {
        if (! config('suite.support.enabled')) {
            $this->error('Vendor support audit binding is disabled.');

            return self::FAILURE;
        }

        if (! $ledger->verifyChain()) {
            $this->error('Vendor operation ledger integrity verification failed.');

            return self::FAILURE;
        }

        $this->info('Vendor operation ledger integrity verified.');

        return self::SUCCESS;
    }
}
