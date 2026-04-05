<?php

declare(strict_types=1);

use App\Domain\Monitoring\Services\EventMigrationService;
use App\Domain\Monitoring\Services\EventMigrationValidator;
use App\Domain\Shared\EventSourcing\EventRouter;

uses(Tests\TestCase::class);

beforeEach(function () {
    $this->router = new EventRouter();
    $this->validator = new EventMigrationValidator();
    $this->service = new EventMigrationService($this->router, $this->validator);
});

describe('EventMigrationService', function () {
    it('generates migration plan for all domains', function () {
        $plan = $this->service->getMigrationPlan();

        // Plan should be an array (may be empty if no events exist in stored_events)
        expect($plan)->toBeArray();
    });

    it('generates migration plan for a specific domain', function () {
        $plan = $this->service->getMigrationPlan('Account');

        expect($plan)->toBeArray();
        // If there are events, only Account should be in the plan
        foreach (array_keys($plan) as $domain) {
            expect($domain)->toBe('Account');
        }
    });

    it('returns empty plan when no events to migrate', function () {
        // With a fresh database, there should be no events
        $plan = $this->service->getMigrationPlan();

        expect($plan)->toBeEmpty();
    });

    it('creates dry-run migration record', function () {
        $migration = $this->service->migrate('Account', 1000, dryRun: true);

        expect($migration->domain)->toBe('Account');
        expect($migration->status)->toBe('dry_run');
        expect($migration->source_table)->toBe('stored_events');
        expect($migration->target_table)->toBe('account_events');
    });

    it('completes migration with zero events', function () {
        $migration = $this->service->migrate('Account', 1000);

        expect($migration->status)->toBe('completed');
        expect($migration->events_migrated)->toBe(0);
    });

    it('verifies migration for domain', function () {
        $result = $this->service->verify('Account');

        expect($result)->toHaveKey('valid');
        expect($result)->toHaveKey('checks');
        expect($result['checks'])->toBeArray();
    });
});

describe('EventMigrationValidator', function () {
    it('validates table existence', function () {
        $validator = new EventMigrationValidator();
        $result = $validator->validate('stored_events', 'nonexistent_table', 'Account');

        expect($result['valid'])->toBeFalse();
        expect($result['checks']['target_table_exists']['passed'])->toBeFalse();
    });
});
