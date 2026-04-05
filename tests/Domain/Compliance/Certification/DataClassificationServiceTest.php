<?php

declare(strict_types=1);

use App\Domain\Compliance\Models\DataClassification;
use App\Domain\Compliance\Services\Certification\DataClassificationService;

describe('DataClassificationService', function () {
    it('seeds default classifications', function () {
        $service = new DataClassificationService();
        $result = $service->seedDefaultClassifications();

        expect($result)->toHaveKey('created')
            ->and($result['created'])->toBeGreaterThan(0);
    });

    it('classifies a field', function () {
        $service = new DataClassificationService();
        $classification = $service->classifyField(
            'App\\Models\\User',
            'email',
            'confidential',
            true,
        );

        expect($classification)->toBeInstanceOf(DataClassification::class)
            ->and($classification->model_class)->toBe('App\\Models\\User')
            ->and($classification->field_name)->toBe('email')
            ->and($classification->classification_level)->toBe('confidential')
            ->and($classification->encryption_required)->toBeTrue();
    });

    it('generates compliance report', function () {
        $service = new DataClassificationService();
        $service->seedDefaultClassifications();

        $report = $service->generateComplianceReport();

        expect($report)->toHaveKey('total_classifications')
            ->and($report['total_classifications'])->toBeGreaterThan(0)
            ->and($report)->toHaveKey('encryption')
            ->and($report['encryption'])->toHaveKey('compliance_rate');
    });

    it('verifies encryption on classified fields', function () {
        $service = new DataClassificationService();
        $service->classifyField('App\\Models\\User', 'password', 'restricted', true);

        $result = $service->verifyEncryption();

        expect($result)->toHaveKey('total')
            ->and($result)->toHaveKey('verified')
            ->and($result)->toHaveKey('unverified');
    });

    it('returns demo report', function () {
        $service = new DataClassificationService();
        $report = $service->getDemoReport();

        expect($report)->toHaveKey('total_classifications')
            ->and($report['total_classifications'])->toBe(47)
            ->and($report['encryption']['compliance_rate'])->toBe(90.0);
    });
});
