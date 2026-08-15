<?php

namespace App\Console\Commands;

use App\Suite\ItsmGateway;
use Illuminate\Console\Command;

class SuitePreflight extends Command
{
    protected $signature = 'fynix:suite-preflight';

    protected $description = 'Validate fail-closed Fynix Suite integration configuration';

    public function handle(ItsmGateway $gateway): int
    {
        if (config('suite.support.enabled')) {
            foreach (['remote_tenant_id', 'webhook_id'] as $key) {
                if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) config('suite.support.'.$key))) {
                    $this->error('Support '.$key.' must be a UUID.');

                    return self::FAILURE;
                }
            }
            $supportSecrets = config('suite.support.webhook_secrets', []);
            if ($supportSecrets === [] || collect($supportSecrets)->contains(fn ($secret) => strlen((string) $secret) < 32)) {
                $this->error('Support webhook secrets must contain at least 32 bytes each.');

                return self::FAILURE;
            }
            if (strlen((string) config('suite.support.ledger_key')) < 32) {
                $this->error('Support ledger key must contain at least 32 bytes.');

                return self::FAILURE;
            }
            if ((int) config('suite.support.integrity_max_age') < 60) {
                $this->error('Support integrity maximum age must be at least 60 seconds.');

                return self::FAILURE;
            }
            if (config('suite.support.anchor.enabled')) {
                if (! config('suite.support.anchor.bucket')) {
                    $this->error('Support anchor bucket is required.');

                    return self::FAILURE;
                }
                if (strlen((string) config('suite.support.anchor.key')) < 32) {
                    $this->error('Support anchor signing key must contain at least 32 bytes.');

                    return self::FAILURE;
                }
                if ((int) config('suite.support.anchor.retention_days') < 365) {
                    $this->error('Support anchor retention must be at least 365 days.');

                    return self::FAILURE;
                }
            }
            $this->info('Fynix vendor support audit binding is valid.');
        } else {
            $this->info('Vendor support audit binding disabled.');
        }

        if (! config('suite.itsm.enabled')) {
            $this->info('ITSM integration disabled.');

            return self::SUCCESS;
        }
        $missing = $gateway->missingConfiguration();
        if ($missing !== []) {
            $this->error('Missing ITSM settings: '.implode(', ', $missing));

            return self::FAILURE;
        }
        foreach (['company_id', 'origin_id', 'ticket_type_id', 'department_id', 'priority_id', 'sync_analyst_id'] as $key) {
            if (filter_var(config('suite.itsm.'.$key), FILTER_VALIDATE_INT) === false || (int) config('suite.itsm.'.$key) < 1) {
                $this->error($key.' must be a positive integer.');

                return self::FAILURE;
            }
        }
        if (! filter_var(config('suite.itsm.base_url'), FILTER_VALIDATE_URL) || ! filter_var(config('suite.itsm.public_url'), FILTER_VALIDATE_URL)) {
            $this->error('ITSM URLs must be absolute.');

            return self::FAILURE;
        }
        if (! preg_match('/^fitsm_[a-f0-9]{48}$/', (string) config('suite.itsm.token'))) {
            $this->error('ITSM token is not a fitsm_ key.');

            return self::FAILURE;
        }
        foreach (['local_tenant_id', 'remote_tenant_id', 'webhook_id'] as $key) {
            if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', (string) config('suite.itsm.'.$key))) {
                $this->error($key.' must be a UUID.');

                return self::FAILURE;
            }
        }
        $this->info('Fynix Suite ITSM configuration is valid.');

        return self::SUCCESS;
    }
}
