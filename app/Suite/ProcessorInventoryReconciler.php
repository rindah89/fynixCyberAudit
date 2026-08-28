<?php

namespace App\Suite;

use App\Models\DataProcessor;
use App\Models\ProcessorInventoryRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

class ProcessorInventoryReconciler
{
    public function __construct(private readonly DataGovernanceControlService $controls) {}

    /** @return array{sources: int, active: int, retired: int} */
    public function reconcile(): array
    {
        $required = config('data_governance.required_sources', []);
        $inventory = config('data_governance.processor_inventory', []);
        $bindings = config('data_governance.bindings', []);

        try {
            $result = DB::transaction(function () use ($required, $inventory, $bindings): array {
                $active = 0;
                $retired = 0;
                foreach ($required as $source) {
                    $entries = $inventory[$source] ?? null;
                    $binding = $bindings[$source] ?? null;
                    if (! is_array($entries) || $entries === []) {
                        throw new InvalidArgumentException("Processor inventory is missing or empty for required source {$source}.");
                    }
                    if (! is_array($binding) || ! ($binding['enabled'] ?? false) || blank($binding['tenant_id'] ?? null)) {
                        throw new InvalidArgumentException("Enabled governance binding is missing for required source {$source}.");
                    }
                    $names = array_map(fn (array $entry): string => trim((string) ($entry['name'] ?? '')), $entries);
                    if (in_array('', $names, true) || count($names) !== count(array_unique($names))) {
                        throw new InvalidArgumentException("Processor names must be non-empty and unique for {$source}.");
                    }
                    foreach ($entries as $entry) {
                        $this->controls->registerProcessor(array_merge($entry, [
                            'tenant_id' => $binding['tenant_id'], 'source' => $source,
                        ]));
                        $active++;
                    }
                    $retired += DataProcessor::query()
                        ->where(['tenant_id' => $binding['tenant_id'], 'source' => $source, 'active' => true])
                        ->whereNotIn('name', $names)
                        ->update(['active' => false, 'status' => 'pending_review', 'reviewed_by' => null, 'reviewed_at' => null, 'review_digest' => null]);
                }

                return ['sources' => count($required), 'active' => $active, 'retired' => $retired];
            });
            ProcessorInventoryRun::create([
                'status' => 'successful', 'source_count' => $result['sources'],
                'active_count' => $result['active'], 'retired_count' => $result['retired'],
                'inventory_digest' => hash('sha256', json_encode($inventory, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
                'completed_at' => now(),
            ]);

            return $result;
        } catch (Throwable $error) {
            ProcessorInventoryRun::create([
                'status' => 'failed', 'error_code' => $error instanceof InvalidArgumentException ? 'validation_failed' : 'reconciliation_failed',
                'completed_at' => now(),
            ]);
            throw $error;
        }
    }
}
