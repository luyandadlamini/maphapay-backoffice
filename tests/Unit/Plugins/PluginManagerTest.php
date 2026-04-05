<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Plugin;
use App\Infrastructure\Plugins\PluginManager;
use App\Infrastructure\Plugins\PluginManifest;

uses(Tests\TestCase::class);

describe('PluginManager', function () {
    it('installs a plugin from manifest', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'      => 'finaegis',
            'name'        => 'test-plugin',
            'version'     => '1.0.0',
            'description' => 'A test plugin',
            'author'      => 'FinAegis',
        ]);

        $result = $manager->install($manifest);

        expect($result['success'])->toBeTrue();
        expect($result['plugin'])->toBeInstanceOf(Plugin::class);
        expect($result['plugin']->vendor)->toBe('finaegis');
        expect($result['plugin']->name)->toBe('test-plugin');
        expect($result['plugin']->status)->toBe('inactive');
    });

    it('prevents duplicate installation', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'duplicate-plugin',
            'version' => '1.0.0',
        ]);

        $manager->install($manifest);
        $result = $manager->install($manifest);

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('already installed');
    });

    it('rejects invalid manifest', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'  => '',
            'name'    => '',
            'version' => 'invalid',
        ]);

        $result = $manager->install($manifest);

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('Invalid plugin manifest');
    });

    it('enables and disables a plugin', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'toggle-plugin',
            'version' => '1.0.0',
        ]);

        $manager->install($manifest);

        $enableResult = $manager->enable('finaegis', 'toggle-plugin');
        expect($enableResult['success'])->toBeTrue();

        $plugin = Plugin::where('vendor', 'finaegis')
            ->where('name', 'toggle-plugin')
            ->first();
        expect($plugin->status)->toBe('active');

        $disableResult = $manager->disable('finaegis', 'toggle-plugin');
        expect($disableResult['success'])->toBeTrue();

        $plugin->refresh();
        expect($plugin->status)->toBe('inactive');
    });

    it('removes a plugin', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'removable-plugin',
            'version' => '1.0.0',
        ]);

        $manager->install($manifest);
        $result = $manager->remove('finaegis', 'removable-plugin');

        expect($result['success'])->toBeTrue();
        expect(Plugin::where('vendor', 'finaegis')
            ->where('name', 'removable-plugin')
            ->exists())->toBeFalse();
    });

    it('prevents removing system plugins', function () {
        $manager = app(PluginManager::class);

        Plugin::create([
            'vendor'    => 'finaegis',
            'name'      => 'core-plugin',
            'version'   => '1.0.0',
            'status'    => 'active',
            'is_system' => true,
            'path'      => '/plugins/finaegis/core-plugin',
        ]);

        $result = $manager->remove('finaegis', 'core-plugin');

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('system plugin');
    });

    it('lists installed plugins', function () {
        $manager = app(PluginManager::class);

        $manager->install(PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'plugin-a',
            'version' => '1.0.0',
        ]));

        $manager->install(PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'plugin-b',
            'version' => '2.0.0',
        ]));

        $plugins = $manager->list();
        expect($plugins)->toHaveCount(2);
    });

    it('updates a plugin version', function () {
        $manager = app(PluginManager::class);

        $manifest = PluginManifest::fromArray([
            'vendor'  => 'finaegis',
            'name'    => 'updatable-plugin',
            'version' => '1.0.0',
        ]);

        $manager->install($manifest);

        $newManifest = PluginManifest::fromArray([
            'vendor'      => 'finaegis',
            'name'        => 'updatable-plugin',
            'version'     => '2.0.0',
            'description' => 'Updated description',
        ]);

        $result = $manager->update('finaegis', 'updatable-plugin', $newManifest);

        expect($result['success'])->toBeTrue();

        $plugin = Plugin::where('vendor', 'finaegis')
            ->where('name', 'updatable-plugin')
            ->first();
        expect($plugin->version)->toBe('2.0.0');
    });
});
