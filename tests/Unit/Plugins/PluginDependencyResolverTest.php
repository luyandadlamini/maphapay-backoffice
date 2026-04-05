<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Plugin;
use App\Infrastructure\Plugins\PluginDependencyResolver;
use App\Infrastructure\Plugins\PluginManifest;

uses(Tests\TestCase::class);

describe('PluginDependencyResolver', function () {
    it('resolves satisfied dependencies', function () {
        Plugin::create([
            'vendor'  => 'finaegis',
            'name'    => 'core',
            'version' => '1.5.0',
            'status'  => 'active',
            'path'    => '/plugins/finaegis/core',
        ]);

        $resolver = new PluginDependencyResolver();

        $manifest = PluginManifest::fromArray([
            'vendor'       => 'finaegis',
            'name'         => 'extension',
            'version'      => '1.0.0',
            'dependencies' => ['finaegis/core' => '^1.0.0'],
        ]);

        $result = $resolver->resolve($manifest);

        expect($result['satisfied'])->toBeTrue();
        expect($result['missing'])->toBeEmpty();
        expect($result['circular'])->toBeFalse();
    });

    it('detects missing dependencies', function () {
        $resolver = new PluginDependencyResolver();

        $manifest = PluginManifest::fromArray([
            'vendor'       => 'finaegis',
            'name'         => 'orphan',
            'version'      => '1.0.0',
            'dependencies' => ['finaegis/missing-dep' => '^1.0.0'],
        ]);

        $result = $resolver->resolve($manifest);

        expect($result['satisfied'])->toBeFalse();
        expect($result['missing'])->toContain('finaegis/missing-dep');
    });

    it('validates caret semver constraint', function () {
        $resolver = new PluginDependencyResolver();

        expect($resolver->satisfiesConstraint('1.5.0', '^1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('1.9.9', '^1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('2.0.0', '^1.0.0'))->toBeFalse();
        expect($resolver->satisfiesConstraint('0.9.0', '^1.0.0'))->toBeFalse();
    });

    it('validates tilde semver constraint', function () {
        $resolver = new PluginDependencyResolver();

        expect($resolver->satisfiesConstraint('1.0.5', '~1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('1.1.0', '~1.0.0'))->toBeFalse();
    });

    it('validates gte semver constraint', function () {
        $resolver = new PluginDependencyResolver();

        expect($resolver->satisfiesConstraint('2.0.0', '>=1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('1.0.0', '>=1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('0.9.0', '>=1.0.0'))->toBeFalse();
    });

    it('validates exact semver constraint', function () {
        $resolver = new PluginDependencyResolver();

        expect($resolver->satisfiesConstraint('1.0.0', '1.0.0'))->toBeTrue();
        expect($resolver->satisfiesConstraint('1.0.1', '1.0.0'))->toBeFalse();
    });

    it('resolves plugin with no dependencies', function () {
        $resolver = new PluginDependencyResolver();

        $manifest = PluginManifest::fromArray([
            'vendor'       => 'finaegis',
            'name'         => 'standalone',
            'version'      => '1.0.0',
            'dependencies' => [],
        ]);

        $result = $resolver->resolve($manifest);

        expect($result['satisfied'])->toBeTrue();
        expect($result['missing'])->toBeEmpty();
    });
});
