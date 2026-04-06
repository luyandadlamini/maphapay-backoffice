<?php

declare(strict_types=1);

namespace Tests;

/**
 * Base test case for controller tests that need to avoid transaction conflicts.
 *
 * Controller tests often create accounts and other models in setUp,
 * which can cause nested transaction issues with event sourcing.
 */
abstract class ControllerTestCase extends TestCase
{
    /**
     * Disable automatic account creation for controller tests.
     * Controller tests should create their own test data as needed.
     */
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }
}
