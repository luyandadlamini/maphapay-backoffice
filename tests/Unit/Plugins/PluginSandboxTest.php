<?php

declare(strict_types=1);

use App\Domain\Shared\Models\Plugin;
use App\Infrastructure\Plugins\PluginPermissions;
use App\Infrastructure\Plugins\PluginSandbox;

uses(Tests\TestCase::class);

describe('PluginSandbox', function () {
    it('allows access with declared permissions', function () {
        $plugin = Plugin::create([
            'vendor'      => 'finaegis',
            'name'        => 'test',
            'version'     => '1.0.0',
            'status'      => 'active',
            'path'        => '/plugins/finaegis/test',
            'permissions' => ['database:read', 'cache:read'],
        ]);

        $sandbox = new PluginSandbox();

        expect($sandbox->hasPermission($plugin, 'database:read'))->toBeTrue();
        expect($sandbox->hasPermission($plugin, 'cache:read'))->toBeTrue();
    });

    it('denies access without declared permissions', function () {
        $plugin = Plugin::create([
            'vendor'      => 'finaegis',
            'name'        => 'restricted',
            'version'     => '1.0.0',
            'status'      => 'active',
            'path'        => '/plugins/finaegis/restricted',
            'permissions' => ['database:read'],
        ]);

        $sandbox = new PluginSandbox();

        expect($sandbox->hasPermission($plugin, 'database:write'))->toBeFalse();
        expect($sandbox->hasPermission($plugin, 'api:external'))->toBeFalse();
    });

    it('throws on enforce without permission', function () {
        $plugin = Plugin::create([
            'vendor'      => 'finaegis',
            'name'        => 'enforced',
            'version'     => '1.0.0',
            'status'      => 'active',
            'path'        => '/plugins/finaegis/enforced',
            'permissions' => [],
        ]);

        $sandbox = new PluginSandbox();

        expect(fn () => $sandbox->enforce($plugin, 'database:write'))
            ->toThrow(RuntimeException::class);
    });

    it('checks multiple permissions with canAccess', function () {
        $plugin = Plugin::create([
            'vendor'      => 'finaegis',
            'name'        => 'multi-perm',
            'version'     => '1.0.0',
            'status'      => 'active',
            'path'        => '/plugins/finaegis/multi-perm',
            'permissions' => ['database:read', 'cache:read', 'events:listen'],
        ]);

        $sandbox = new PluginSandbox();

        expect($sandbox->canAccess($plugin, ['database:read', 'cache:read']))->toBeTrue();
        expect($sandbox->canAccess($plugin, ['database:read', 'api:external']))->toBeFalse();
    });

    it('reports missing permissions', function () {
        $plugin = Plugin::create([
            'vendor'      => 'finaegis',
            'name'        => 'missing-perms',
            'version'     => '1.0.0',
            'status'      => 'active',
            'path'        => '/plugins/finaegis/missing-perms',
            'permissions' => ['database:read'],
        ]);

        $sandbox = new PluginSandbox();

        $missing = $sandbox->getMissingPermissions($plugin, [
            'database:read',
            'database:write',
            'api:external',
        ]);

        expect($missing)->toContain('database:write');
        expect($missing)->toContain('api:external');
        expect($missing)->not->toContain('database:read');
    });
});

describe('PluginPermissions', function () {
    it('lists all permissions', function () {
        $all = PluginPermissions::all();
        expect($all)->toContain('database:read');
        expect($all)->toContain('api:external');
        expect(count($all))->toBeGreaterThanOrEqual(12);
    });

    it('validates permissions', function () {
        $result = PluginPermissions::validate(['database:read', 'invalid:perm']);
        expect($result['valid'])->toBeFalse();
        expect($result['invalid'])->toContain('invalid:perm');
    });

    it('groups permissions by category', function () {
        $grouped = PluginPermissions::grouped();
        expect($grouped)->toHaveKey('Data Access');
        expect($grouped)->toHaveKey('API');
        expect($grouped['Data Access'])->toContain('database:read');
    });
});
