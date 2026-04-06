<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base test case for domain tests (aggregates, repositories, etc.) that need to avoid transaction conflicts.
 *
 * This class prevents automatic account creation during setUp to avoid
 * nested transaction issues with event sourcing aggregates.
 */
abstract class DomainTestCase extends TestCase
{
    /**
     * Disable automatic account creation for domain tests.
     * Domain tests typically work with aggregates and events directly.
     */
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }
}
