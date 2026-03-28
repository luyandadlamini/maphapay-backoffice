<?php

declare(strict_types=1);

use App\Domain\Shared\Money\MoneyConverter;

describe('MoneyConverter::toSmallestUnit', function () {

    it('converts a clean 2-decimal SZL string to minor units', function () {
        expect(MoneyConverter::toSmallestUnit('25.10', 2))->toBe(2510);
    });

    it('converts a whole number string', function () {
        expect(MoneyConverter::toSmallestUnit('100', 2))->toBe(10000);
    });

    it('converts a single decimal', function () {
        expect(MoneyConverter::toSmallestUnit('25.1', 2))->toBe(2510);
    });

    it('converts zero', function () {
        expect(MoneyConverter::toSmallestUnit('0', 2))->toBe(0);
        expect(MoneyConverter::toSmallestUnit('0.00', 2))->toBe(0);
    });

    it('rounds half-up to match mobile Math.round / roundToSZL', function () {
        // 25.005 * 100 = 2500.5 → rounds up to 2501
        expect(MoneyConverter::toSmallestUnit('25.005', 2))->toBe(2501);
    });

    it('rounds down when fractional part is below 0.5', function () {
        // 25.004 * 100 = 2500.4 → rounds down to 2500
        expect(MoneyConverter::toSmallestUnit('25.004', 2))->toBe(2500);
    });

    it('handles large amounts without float drift', function () {
        // A large transfer that would lose precision with PHP floats
        expect(MoneyConverter::toSmallestUnit('999999.99', 2))->toBe(99999999);
    });

    it('handles precision=0 (no decimals)', function () {
        expect(MoneyConverter::toSmallestUnit('100', 0))->toBe(100);
    });

    it('handles high-precision crypto assets', function () {
        // 8 decimal places, e.g. BTC
        expect(MoneyConverter::toSmallestUnit('0.00000001', 8))->toBe(1);
        expect(MoneyConverter::toSmallestUnit('1.00000000', 8))->toBe(100000000);
    });

    it('throws for a negative amount string', function () {
        expect(fn () => MoneyConverter::toSmallestUnit('-1.00', 2))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for a non-numeric string', function () {
        expect(fn () => MoneyConverter::toSmallestUnit('abc', 2))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for an empty string', function () {
        expect(fn () => MoneyConverter::toSmallestUnit('', 2))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws for negative precision', function () {
        expect(fn () => MoneyConverter::toSmallestUnit('10.00', -1))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('MoneyConverter::toMajorUnitString', function () {

    it('converts minor units back to a 2-decimal string', function () {
        expect(MoneyConverter::toMajorUnitString(2510, 2))->toBe('25.10');
    });

    it('zero-pads the decimal part', function () {
        expect(MoneyConverter::toMajorUnitString(100, 2))->toBe('1.00');
    });

    it('handles zero', function () {
        expect(MoneyConverter::toMajorUnitString(0, 2))->toBe('0.00');
    });

    it('round-trips with toSmallestUnit', function () {
        $original = '123.45';
        $minor    = MoneyConverter::toSmallestUnit($original, 2);
        $back     = MoneyConverter::toMajorUnitString($minor, 2);

        expect($back)->toBe($original);
    });

    it('handles large amounts', function () {
        expect(MoneyConverter::toMajorUnitString(99999999, 2))->toBe('999999.99');
    });
});

describe('MoneyConverter::normalise', function () {

    it('pads a single decimal to full precision', function () {
        expect(MoneyConverter::normalise('25.1', 2))->toBe('25.10');
    });

    it('pads a whole number to full precision', function () {
        expect(MoneyConverter::normalise('25', 2))->toBe('25.00');
    });

    it('preserves an already-correct string', function () {
        expect(MoneyConverter::normalise('25.10', 2))->toBe('25.10');
    });

    it('throws for a non-numeric string', function () {
        expect(fn () => MoneyConverter::normalise('not-a-number', 2))
            ->toThrow(InvalidArgumentException::class);
    });
});
