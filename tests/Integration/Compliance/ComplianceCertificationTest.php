<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

describe('Compliance Certification Artisan Commands', function () {
    it('runs evidence collection command', function () {
        config(['compliance-certification.soc2.demo_mode' => true]);

        $exitCode = Artisan::call('compliance:collect-evidence', [
            '--period' => 'quarterly',
            '--format' => 'json',
        ]);

        expect($exitCode)->toBe(0);
    });

    it('runs access review command', function () {
        config(['compliance-certification.soc2.demo_mode' => true]);

        $exitCode = Artisan::call('compliance:access-review', [
            '--format' => 'json',
        ]);

        expect($exitCode)->toBe(0);
    });

    it('runs incident stats command', function () {
        $exitCode = Artisan::call('compliance:incident', [
            'action' => 'stats',
        ]);

        expect($exitCode)->toBe(0);
    });
});
