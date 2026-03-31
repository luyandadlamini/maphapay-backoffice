<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Domain\Mobile\Models\PocketSmartRule;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

class PocketsStoreControllerTest extends ControllerTestCase
{
    private const ROUTE = '/api/pockets/store';

    #[Test]
    public function test_creates_pocket_and_smart_rule_with_ulid_primary_keys(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['read', 'write', 'delete']);

        $response = $this->postJson(self::ROUTE, [
            'name'          => 'Holiday',
            'target_amount' => 10000,
            'target_date'   => '2027-06-01',
            'category'      => 'travel',
            'color'         => '#4F8CFF',
        ]);

        $response->assertCreated()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('data.pocket.name', 'Holiday');

        $pocketUuid = $response->json('data.pocket.id');
        self::assertIsString($pocketUuid);
        self::assertTrue((bool) preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $pocketUuid,
        ));

        $pocket = Pocket::where('uuid', $pocketUuid)->first();
        self::assertNotNull($pocket);
        self::assertNotEmpty($pocket->id);

        $rule = PocketSmartRule::where('pocket_id', $pocketUuid)->first();
        self::assertNotNull($rule);
        self::assertNotEmpty($rule->id);
    }
}
