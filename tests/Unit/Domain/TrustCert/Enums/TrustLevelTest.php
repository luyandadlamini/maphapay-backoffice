<?php

declare(strict_types=1);

use App\Domain\TrustCert\Enums\TrustLevel;

describe('TrustLevel Enum', function (): void {
    it('has all expected levels', function (): void {
        $levels = TrustLevel::cases();

        expect($levels)->toHaveCount(5);
        expect(TrustLevel::UNKNOWN->value)->toBe('unknown');
        expect(TrustLevel::BASIC->value)->toBe('basic');
        expect(TrustLevel::VERIFIED->value)->toBe('verified');
        expect(TrustLevel::HIGH->value)->toBe('high');
        expect(TrustLevel::ULTIMATE->value)->toBe('ultimate');
    });

    it('returns correct labels', function (): void {
        expect(TrustLevel::UNKNOWN->label())->toBe('Unknown');
        expect(TrustLevel::BASIC->label())->toBe('Basic');
        expect(TrustLevel::VERIFIED->label())->toBe('Verified');
        expect(TrustLevel::HIGH->label())->toBe('High');
        expect(TrustLevel::ULTIMATE->label())->toBe('Ultimate');
    });

    it('returns correct numeric values', function (): void {
        expect(TrustLevel::UNKNOWN->numericValue())->toBe(0);
        expect(TrustLevel::BASIC->numericValue())->toBe(1);
        expect(TrustLevel::VERIFIED->numericValue())->toBe(2);
        expect(TrustLevel::HIGH->numericValue())->toBe(3);
        expect(TrustLevel::ULTIMATE->numericValue())->toBe(4);
    });

    it('correctly checks minimum trust level', function (): void {
        expect(TrustLevel::ULTIMATE->meetsMinimum(TrustLevel::BASIC))->toBeTrue();
        expect(TrustLevel::HIGH->meetsMinimum(TrustLevel::VERIFIED))->toBeTrue();
        expect(TrustLevel::VERIFIED->meetsMinimum(TrustLevel::VERIFIED))->toBeTrue();
        expect(TrustLevel::BASIC->meetsMinimum(TrustLevel::HIGH))->toBeFalse();
        expect(TrustLevel::UNKNOWN->meetsMinimum(TrustLevel::BASIC))->toBeFalse();
    });

    it('returns correct requirements', function (): void {
        expect(TrustLevel::UNKNOWN->requirements())->toBeArray()->not->toBeEmpty();
        expect(TrustLevel::BASIC->requirements())->toContain('Government-issued ID');
        expect(TrustLevel::VERIFIED->requirements())->toContain('Proof of address');
        expect(TrustLevel::HIGH->requirements())->toContain('Source of funds documentation');
        expect(TrustLevel::ULTIMATE->requirements())->not->toBeEmpty();
    });

    it('returns correct document types per level', function (): void {
        expect(TrustLevel::UNKNOWN->documents())->toBe([]);
        expect(TrustLevel::BASIC->documents())->toBe(['id_front', 'id_back', 'selfie']);
        expect(TrustLevel::VERIFIED->documents())->toContain('proof_of_address');
        expect(TrustLevel::HIGH->documents())->toContain('source_of_funds');
    });
});
