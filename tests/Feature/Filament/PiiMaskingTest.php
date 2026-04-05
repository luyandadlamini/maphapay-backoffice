<?php

use App\Filament\Admin\Concerns\MasksPii;
use App\Models\User;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);
});

it('maskPhone masks mobile number when user lacks view-pii', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskPhone($v);
        }
    })->mask('76123456');

    expect($result)->toBe('7612****456');
});

it('maskPhone passes through when user has view-pii', function (): void {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $ops->givePermissionTo('view-pii');
    $this->actingAs($ops);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskPhone($v);
        }
    })->mask('76123456');

    expect($result)->toBe('76123456');
});

it('maskEmail masks email when user lacks view-pii', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskEmail($v);
        }
    })->mask('user@example.com');

    expect($result)->toBe('us***@example.com');
});

it('maskEmail passes through when user has view-pii', function (): void {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $ops->givePermissionTo('view-pii');
    $this->actingAs($ops);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskEmail($v);
        }
    })->mask('user@example.com');

    expect($result)->toBe('user@example.com');
});

it('maskNationalId masks ID when user lacks view-pii', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskNationalId($v);
        }
    })->mask('123456789');

    expect($result)->toBe('***-****-789');
});

it('maskNationalId passes through when user has view-pii', function (): void {
    $ops = User::factory()->create();
    $ops->assignRole('operations-l2');
    $ops->givePermissionTo('view-pii');
    $this->actingAs($ops);

    $result = (new class {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskNationalId($v);
        }
    })->mask('123456789');

    expect($result)->toBe('123456789');
});
