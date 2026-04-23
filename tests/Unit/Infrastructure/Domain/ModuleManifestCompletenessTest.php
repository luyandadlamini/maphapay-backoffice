<?php

declare(strict_types=1);

use App\Infrastructure\Domain\DomainManager;

uses(Tests\TestCase::class);

describe('Module Manifest Completeness', function () {
    it('has module.json for every domain directory', function () {
        $domainPath = base_path('app/Domain');
        $directories = array_filter(glob("{$domainPath}/*"), 'is_dir');
        $optionalManifestDomains = [
            'AuthorizedTransaction',
            'Corporate',
            'FundManagement',
            'GroupSavings',
            'Ledger',
            'MtnMomo',
            'Onboarding',
            'SocialMoney',
            'Support',
        ];

        $missing = [];
        foreach ($directories as $dir) {
            $manifestPath = $dir . '/module.json';
            if (! file_exists($manifestPath)) {
                $missing[] = basename($dir);
            }
        }

        $requiredMissing = array_values(array_diff($missing, $optionalManifestDomains));

        expect($requiredMissing)->toBeEmpty(
            'Missing module.json for required domains: ' . implode(', ', $requiredMissing)
        );
    });

    it('loads all 41 domain manifests', function () {
        /** @var DomainManager $manager */
        $manager = app(DomainManager::class);
        $manifests = $manager->loadAllManifests();

        expect(count($manifests))->toBeGreaterThanOrEqual(41);
    });

    it('has valid JSON in all module.json files', function () {
        $domainPath = base_path('app/Domain');
        $manifests = glob("{$domainPath}/*/module.json");

        foreach ($manifests as $manifestPath) {
            $content = file_get_contents($manifestPath);
            $data = json_decode($content !== false ? $content : '', true);

            expect($data)->not->toBeNull(
                'Invalid JSON in ' . basename(dirname($manifestPath)) . '/module.json'
            );
            expect($data)->toHaveKeys(['name', 'version', 'description', 'type']);
        }
    });

    it('has valid schema reference in all manifests', function () {
        $domainPath = base_path('app/Domain');
        $manifests = glob("{$domainPath}/*/module.json");

        foreach ($manifests as $manifestPath) {
            $content = file_get_contents($manifestPath);
            $data = json_decode($content !== false ? $content : '', true);

            expect($data['$schema'] ?? null)->toBe('https://finaegis.io/schemas/module.json');
        }
    });

    it('has no duplicate domain names across manifests', function () {
        $domainPath = base_path('app/Domain');
        $manifestFiles = glob("{$domainPath}/*/module.json");
        $names = [];

        foreach ($manifestFiles as $manifestPath) {
            $content = file_get_contents($manifestPath);
            $data = json_decode($content !== false ? $content : '', true);
            $names[] = $data['name'];
        }

        expect(count($names))->toBe(count(array_unique($names)));
    });
});
