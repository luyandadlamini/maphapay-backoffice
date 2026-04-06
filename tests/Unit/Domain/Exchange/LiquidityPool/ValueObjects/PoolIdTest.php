<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Exchange\LiquidityPool\ValueObjects;

use App\Domain\Exchange\LiquidityPool\ValueObjects\PoolId;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PoolIdTest extends TestCase
{
    #[Test]
    public function test_can_create_valid_pool_id(): void
    {
        $uuid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
        $poolId = new PoolId($uuid);

        $this->assertEquals($uuid, $poolId->getValue());
        $this->assertEquals($uuid, (string) $poolId);
    }

    #[Test]
    public function test_converts_to_lowercase(): void
    {
        $uuid = 'A0EEBC99-9C0B-4EF8-BB6D-6BB9BD380A11';
        $poolId = new PoolId($uuid);

        $this->assertEquals(strtolower($uuid), $poolId->getValue());
    }

    #[Test]
    public function test_throws_exception_for_empty_value(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pool ID cannot be empty');

        new PoolId('');
    }

    #[Test]
    public function test_throws_exception_for_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pool ID must be a valid UUID');

        new PoolId('not-a-uuid');
    }

    #[Test]
    public function test_can_generate_new_pool_id(): void
    {
        $poolId = PoolId::generate();

        $this->assertInstanceOf(PoolId::class, $poolId);
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/',
            $poolId->getValue()
        );
    }

    #[Test]
    public function test_can_create_from_string(): void
    {
        $uuid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
        $poolId = PoolId::fromString($uuid);

        $this->assertInstanceOf(PoolId::class, $poolId);
        $this->assertEquals($uuid, $poolId->getValue());
    }

    #[Test]
    public function test_equals_method(): void
    {
        $uuid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
        $poolId1 = new PoolId($uuid);
        $poolId2 = new PoolId($uuid);
        $poolId3 = PoolId::generate();

        $this->assertTrue($poolId1->equals($poolId2));
        $this->assertFalse($poolId1->equals($poolId3));
    }

    #[Test]
    public function test_to_array(): void
    {
        $uuid = 'a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11';
        $poolId = new PoolId($uuid);

        $this->assertEquals(['pool_id' => $uuid], $poolId->toArray());
    }
}
