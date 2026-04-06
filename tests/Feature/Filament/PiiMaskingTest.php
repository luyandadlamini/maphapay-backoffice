<?php

declare(strict_types=1);

use App\Filament\Admin\Concerns\MasksPii;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $panel = Filament::getPanel('admin');
    Filament::setCurrentPanel($panel);
    Filament::setServingStatus(true);
    $panel->boot();
});

it('maskPhone masks mobile number when user lacks view-pii', function (): void {
    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $result = (new class () {
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

    $result = (new class () {
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

    $result = (new class () {
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

    $result = (new class () {
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

    $result = (new class () {
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

    $result = (new class () {
        use MasksPii;

        public function mask(string $v): string
        {
            return static::maskNationalId($v);
        }
    })->mask('123456789');

    expect($result)->toBe('123456789');
});

it('maskPhone returns empty string for null input', function (): void {
    $result = (new class () {
        use MasksPii;

        public function mask(?string $v): string
        {
        return static::maskPhone($v);
        }
    })->mask(null);

    expect($result)->toBe('');
});

it('maskEmail passes through malformed email without @ symbol', function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $support = User::factory()->create();
    $support->assignRole('support-l1');
    $this->actingAs($support);

    $result = (new class () {
        use MasksPii;

        public function mask(?string $v): string
        {
        return static::maskEmail($v);
        }
    })->mask('not-an-email');

    expect($result)->toBe('not-an-email');
});

it('can mask PII natively in a Filament List Users View', function (): void {
    $this->artisan('db:seed', ['--class' => 'RolesAndPermissionsSeeder']);

    $support = User::factory()->create(['email' => 'support@example.com']);
    $support->assignRole('support-l1');

    $customer = User::factory()->create([
        'email'  => 'joedoe@maphapay.com',
        'mobile' => '76123456',
    ]);

    // Use Filament's table testing API which handles deferred table loading.
    // assertTableColumnFormattedStateSet calls the column's formatStateUsing
    // callback directly on the record, bypassing Livewire's lazy-render.
    Livewire\Livewire::actingAs($support)
        ->test(App\Filament\Admin\Resources\UserResource\Pages\ListUsers::class)
        ->assertTableColumnFormattedStateSet('email', 'jo***@maphapay.com', record: $customer)
        ->assertTableColumnFormattedStateSet('mobile', '7612****456', record: $customer);
});
