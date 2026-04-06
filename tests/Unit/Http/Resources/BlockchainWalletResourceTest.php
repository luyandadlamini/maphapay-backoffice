<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Resources;

use App\Http\Resources\BlockchainWalletResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlockchainWalletResourceTest extends TestCase
{
    #[Test]
    public function test_transforms_wallet_to_array(): void
    {
        $wallet = (object) [
            'wallet_id' => 'wallet_123',
            'type'      => 'hot',
            'status'    => 'active',
            'metadata'  => json_encode([
                'last_backup' => [
                    'created_at' => '2025-01-01T00:00:00Z',
                    'backup_id'  => 'backup_123',
                ],
                'freeze_reason' => null,
                'frozen_at'     => null,
            ]),
            'settings' => json_encode([
                'auto_backup'      => true,
                'backup_frequency' => 'daily',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $this->assertEquals([
            'wallet_id' => 'wallet_123',
            'type'      => 'hot',
            'status'    => 'active',
            'settings'  => [
                'auto_backup'      => true,
                'backup_frequency' => 'daily',
            ],
            'has_backup'     => true,
            'last_backup_at' => '2025-01-01T00:00:00Z',
            'freeze_reason'  => null,
            'frozen_at'      => null,
            'created_at'     => $wallet->created_at,
            'updated_at'     => $wallet->updated_at,
        ], $array);
    }

    #[Test]
    public function test_handles_frozen_wallet(): void
    {
        $wallet = (object) [
            'wallet_id' => 'wallet_frozen',
            'type'      => 'cold',
            'status'    => 'frozen',
            'metadata'  => json_encode([
                'freeze_reason' => 'Suspicious activity detected',
                'frozen_at'     => '2025-01-15T10:00:00Z',
            ]),
            'settings'   => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $this->assertEquals('frozen', $array['status']);
        $this->assertEquals('Suspicious activity detected', $array['freeze_reason']);
        $this->assertEquals('2025-01-15T10:00:00Z', $array['frozen_at']);
    }

    #[Test]
    public function test_handles_wallet_without_backup(): void
    {
        $wallet = (object) [
            'wallet_id' => 'wallet_no_backup',
            'type'      => 'hot',
            'status'    => 'active',
            'metadata'  => json_encode([
                'other_data' => 'value',
            ]),
            'settings'   => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $this->assertFalse($array['has_backup']);
        $this->assertNull($array['last_backup_at']);
    }

    #[Test]
    public function test_handles_null_metadata(): void
    {
        $wallet = (object) [
            'wallet_id'  => 'wallet_null',
            'type'       => 'hot',
            'status'     => 'active',
            'metadata'   => null,
            'settings'   => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $this->assertIsArray($array['settings']);
        $this->assertEmpty($array['settings']);
        $this->assertFalse($array['has_backup']);
        $this->assertNull($array['last_backup_at']);
        $this->assertNull($array['freeze_reason']);
        $this->assertNull($array['frozen_at']);
    }

    #[Test]
    public function test_handles_invalid_json(): void
    {
        $wallet = (object) [
            'wallet_id'  => 'wallet_invalid',
            'type'       => 'hot',
            'status'     => 'active',
            'metadata'   => 'invalid json',
            'settings'   => 'invalid json',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $this->assertIsArray($array['settings']);
        $this->assertEmpty($array['settings']);
        $this->assertFalse($array['has_backup']);
    }

    #[Test]
    public function test_includes_all_required_fields(): void
    {
        $wallet = (object) [
            'wallet_id'  => 'wallet_fields',
            'type'       => 'hot',
            'status'     => 'active',
            'metadata'   => json_encode([]),
            'settings'   => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $resource = new BlockchainWalletResource($wallet);
        $request = Request::create('/');
        $array = $resource->toArray($request);

        $expectedKeys = [
            'wallet_id',
            'type',
            'status',
            'settings',
            'has_backup',
            'last_backup_at',
            'freeze_reason',
            'frozen_at',
            'created_at',
            'updated_at',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $array);
        }
    }

    #[Test]
    public function test_resource_collection(): void
    {
        $wallets = [
            (object) [
                'wallet_id'  => 'wallet_1',
                'type'       => 'hot',
                'status'     => 'active',
                'metadata'   => null,
                'settings'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            (object) [
                'wallet_id'  => 'wallet_2',
                'type'       => 'cold',
                'status'     => 'active',
                'metadata'   => null,
                'settings'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            (object) [
                'wallet_id'  => 'wallet_3',
                'type'       => 'hot',
                'status'     => 'frozen',
                'metadata'   => null,
                'settings'   => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        $collection = BlockchainWalletResource::collection($wallets);
        $request = Request::create('/');
        $array = $collection->toArray($request);

        $this->assertCount(3, $array);

        foreach ($array as $index => $item) {
            $this->assertEquals($wallets[$index]->wallet_id, $item['wallet_id']);
            $this->assertEquals($wallets[$index]->type, $item['type']);
        }
    }
}
