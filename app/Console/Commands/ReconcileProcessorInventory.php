<?php

namespace App\Console\Commands;

use App\Suite\ProcessorInventoryReconciler;
use Illuminate\Console\Command;

class ReconcileProcessorInventory extends Command
{
    protected $signature = 'fynix:reconcile-processor-inventory';

    protected $description = 'Reconcile the complete configured processor and transfer inventory';

    public function handle(ProcessorInventoryReconciler $reconciler): int
    {
        $this->line(json_encode($reconciler->reconcile(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
