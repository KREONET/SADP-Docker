<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * Development seeders create demo data with fixed credentials (API tokens, SSH keys, OAuth secrets,
 * S3 keys and user passwords that are published in this repository). They must never run against a
 * production instance by accident, so they abort unless the operator explicitly sets
 * SEED_DEMO_DATA_IN_PRODUCTION=true.
 */
trait RefusesProductionSeeding
{
    public const DEMO_SEEDING_OVERRIDE_ENV = 'SEED_DEMO_DATA_IN_PRODUCTION';

    /**
     * @throws RuntimeException When the application runs in production without the override
     */
    protected function refuseToRunInProduction(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        // Read the process environment directly: this is an operator override for a one-off
        // console command and must not be baked into the cached configuration.
        if (filter_var(env(self::DEMO_SEEDING_OVERRIDE_ENV, false), FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s seeds demo data with publicly known credentials and refuses to run in production. '.
            'Use the ProductionSeeder instead, or set %s=true if you really want demo data on this instance.',
            class_basename(static::class),
            self::DEMO_SEEDING_OVERRIDE_ENV,
        ));
    }
}
