<?php

declare(strict_types=1);

use App\Domain\Account\Values\DefaultAccountNames;

it('has correct enum values', function () {
    expect(DefaultAccountNames::MAIN->value)->toBe('Main');
    expect(DefaultAccountNames::SAVINGS->value)->toBe('Savings');
    expect(DefaultAccountNames::LOAN->value)->toBe('Loan');
});

it('returns main as default', function () {
    expect(DefaultAccountNames::default())->toBe(DefaultAccountNames::MAIN);
});

it('generates labels for translation', function () {
    expect(DefaultAccountNames::MAIN->label())->toBe(__('accounts.names.main'));
    expect(DefaultAccountNames::SAVINGS->label())->toBe(__('accounts.names.savings'));
    expect(DefaultAccountNames::LOAN->label())->toBe(__('accounts.names.loan'));
});

it('can get all cases', function () {
    $cases = DefaultAccountNames::cases();

    expect($cases)->toHaveCount(3);
    expect($cases)->toContain(DefaultAccountNames::MAIN);
    expect($cases)->toContain(DefaultAccountNames::SAVINGS);
    expect($cases)->toContain(DefaultAccountNames::LOAN);
});
