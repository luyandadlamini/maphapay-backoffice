<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\DataTransferLog;
use App\Domain\Compliance\Services\Certification\DataResidencyService;

describe('DataResidencyService', function () {
    it('returns default region when no mapping exists', function () {
        $service = new DataResidencyService();
        $region = $service->getRegionForTenant('nonexistent-tenant');

        expect($region)->toBe('EU');
    });

    it('sets and retrieves tenant region', function () {
        $service = new DataResidencyService();
        $service->setTenantRegion('test-tenant', 'US');

        $region = $service->getRegionForTenant('test-tenant');

        expect($region)->toBe('US');
    });

    it('validates cross-region transfers', function () {
        $service = new DataResidencyService();
        $result = $service->validateTransfer('EU', 'US', 'personal_data');

        expect($result)->toHaveKey('from_region')
            ->and($result['from_region'])->toBe('EU')
            ->and($result['to_region'])->toBe('US')
            ->and($result['is_cross_region'])->toBeTrue();
    });

    it('logs data transfers', function () {
        $service = new DataResidencyService();
        $log = $service->logTransfer('EU', 'US', 'personal_data', 'Business requirement');

        expect($log)->toBeInstanceOf(DataTransferLog::class)
            ->and($log->from_region)->toBe('EU')
            ->and($log->to_region)->toBe('US')
            ->and($log->data_type)->toBe('personal_data');
    });

    it('returns residency status', function () {
        $service = new DataResidencyService();
        $status = $service->getResidencyStatus();

        expect($status)->toHaveKey('enabled')
            ->and($status)->toHaveKey('default_region')
            ->and($status)->toHaveKey('available_regions');
    });

    it('returns demo status', function () {
        $service = new DataResidencyService();
        $status = $service->getDemoStatus();

        expect($status['enabled'])->toBeTrue()
            ->and($status['available_regions'])->toContain('EU', 'US')
            ->and($status['tenant_mappings'])->toBe(12);
    });
});
