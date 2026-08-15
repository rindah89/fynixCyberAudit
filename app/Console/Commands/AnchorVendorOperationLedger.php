<?php

namespace App\Console\Commands;

use App\Suite\VendorOperationAnchor;
use App\Suite\VendorOperationLedger;
use Illuminate\Console\Command;
use Throwable;

class AnchorVendorOperationLedger extends Command
{
    protected $signature = 'fynix:vendor-ledger-anchor';

    protected $description = 'Verify and export the vendor operation ledger head to immutable object storage';

    public function handle(VendorOperationLedger $ledger, VendorOperationAnchor $anchor): int
    {
        if (! $ledger->verifyChain()) {
            $this->error('Vendor operation ledger integrity verification failed; no anchor was written.');

            return self::FAILURE;
        }

        try {
            $objectKey = $anchor->publish($ledger->head());
        } catch (Throwable $error) {
            report($error);
            $this->error('Vendor operation ledger anchor export failed.');

            return self::FAILURE;
        }

        $this->info('Vendor operation ledger anchor written: '.$objectKey);

        return self::SUCCESS;
    }
}
