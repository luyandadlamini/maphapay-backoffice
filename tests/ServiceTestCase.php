<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base test case for service tests that need to avoid transaction conflicts.
 *
 * This class prevents automatic account creation during setUp to avoid
 * nested transaction issues with event sourcing aggregates.
 */
abstract class ServiceTestCase extends TestCase
{
    /**
     * Disable automatic account creation for service tests.
     * Service tests typically create their own test data as needed.
     */
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }
}
