<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\KeyRotationSchedule;
use App\Domain\Compliance\Services\Certification\KeyRotationService;


describe('KeyRotationService', function () {
    it('registers a key for rotation tracking', function () {
        $service = new KeyRotationService();
        $schedule = $service->registerKey('app_key', 'test_app_key', 90);

        expect($schedule)->toBeInstanceOf(KeyRotationSchedule::class)
            ->and($schedule->key_type)->toBe('app_key')
            ->and($schedule->key_identifier)->toBe('test_app_key')
            ->and($schedule->rotation_interval_days)->toBe(90)
            ->and($schedule->status)->toBe('active')
            ->and($schedule->next_rotation_at)->not->toBeNull();
    });

    it('initializes default key inventory', function () {
        $service = new KeyRotationService();
        $result = $service->initializeDefaultKeys();

        expect($result)->toHaveKey('registered')
            ->and($result['registered'])->toBeGreaterThan(0)
            ->and($result)->toHaveKey('total');
    });

    it('detects overdue keys', function () {
        $service = new KeyRotationService();
        $schedule = $service->registerKey('test_key', 'overdue_test_key', 1);

        // Manually set next_rotation_at to the past
        $schedule->update(['next_rotation_at' => now()->subDays(5)]);

        $overdue = $service->getOverdueKeys();

        expect($overdue)->toHaveCount(1)
            ->and($overdue->first()->key_identifier)->toBe('overdue_test_key');
    });

    it('rotates a key in dry-run mode', function () {
        $service = new KeyRotationService();
        $service->registerKey('app_key', 'dry_run_test', 90);

        $result = $service->rotateKey('dry_run_test', true);

        expect($result['success'])->toBeTrue()
            ->and($result['dry_run'])->toBeTrue()
            ->and($result['key_identifier'])->toBe('dry_run_test');
    });

    it('rotates a key', function () {
        $service = new KeyRotationService();
        $service->registerKey('app_key', 'rotate_test', 90);

        $result = $service->rotateKey('rotate_test');

        expect($result['success'])->toBeTrue()
            ->and($result['key_identifier'])->toBe('rotate_test')
            ->and($result)->toHaveKey('next_rotation_at');

        $schedule = KeyRotationSchedule::where('key_identifier', 'rotate_test')->first();
        expect($schedule->rotation_history)->toBeArray()
            ->and($schedule->rotation_history)->toHaveCount(1);
    });

    it('generates rotation report', function () {
        $service = new KeyRotationService();
        $service->initializeDefaultKeys();

        $report = $service->generateRotationReport();

        expect($report)->toHaveKey('total_keys')
            ->and($report['total_keys'])->toBeGreaterThan(0)
            ->and($report)->toHaveKey('compliance_rate')
            ->and($report['compliance_rate'])->toBe(100.0);
    });

    it('returns demo report', function () {
        $service = new KeyRotationService();
        $report = $service->getDemoReport();

        expect($report['total_keys'])->toBe(6)
            ->and($report['compliance_rate'])->toBe(100.0);
    });

    it('returns failure for unknown key', function () {
        $service = new KeyRotationService();
        $result = $service->rotateKey('nonexistent_key');

        expect($result['success'])->toBeFalse();
    });
});
