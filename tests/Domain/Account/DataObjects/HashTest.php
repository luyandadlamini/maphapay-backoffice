<?php

declare(strict_types=1);

use App\Domain\Account\DataObjects\Hash;

it('can be created with valid SHA3-512 hash', function () {
    $validHash = str_repeat('a', 128); // 128 hex characters for SHA3-512
    $hash = new Hash($validHash);

    expect($hash->getHash())->toBe($validHash);
});

it('returns hash as string', function () {
    $validHash = str_repeat('f', 128);
    $hash = new Hash($validHash);

    expect($hash->getHash())->toBeString();
});

it('can be converted to array', function () {
    $validHash = str_repeat('1', 128);
    $hash = new Hash($validHash);

    $array = $hash->toArray();

    expect($array)->toHaveKey('hash', $validHash);
});

it('implements data object contract', function () {
    $validHash = str_repeat('0', 128);
    $hash = new Hash($validHash);

    expect($hash)->toBeInstanceOf(JustSteveKing\DataObjects\Contracts\DataObjectContract::class);
});

it('validates hash length must be 128 characters', function () {
    $validHash = str_repeat('a', 128);
    $hash = new Hash($validHash);

    expect($hash->getHash())->toBe($validHash);
    expect(strlen($hash->getHash()))->toBe(128);
});

it('throws exception for invalid hash length', function () {
    expect(function () {
        new Hash('short-hash');
    })->toThrow(InvalidArgumentException::class);
});

it('throws exception for non-hexadecimal characters', function () {
    $invalidHash = str_repeat('g', 128); // 'g' is not hexadecimal

    expect(function () use ($invalidHash) {
        new Hash($invalidHash);
    })->toThrow(InvalidArgumentException::class);
});

it('can compare hashes for equality', function () {
    $hashValue = str_repeat('abc123', 21) . 'ab'; // 128 chars
    $hash1 = new Hash($hashValue);
    $hash2 = new Hash($hashValue);

    expect($hash1->equals($hash2))->toBeTrue();
});

it('can detect hash inequality', function () {
    $hash1 = new Hash(str_repeat('a', 128));
    $hash2 = new Hash(str_repeat('b', 128));

    expect($hash1->equals($hash2))->toBeFalse();
});
