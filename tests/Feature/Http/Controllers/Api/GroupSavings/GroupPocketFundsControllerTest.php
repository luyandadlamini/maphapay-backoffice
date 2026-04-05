<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\GroupSavings;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Models\GroupPocket;
use App\Models\Thread;
use App\Models\ThreadParticipant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GroupPocketFundsControllerTest extends TestCase
{
    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['banking.default_currency' => 'SZL']);

        Asset::firstOrCreate(
            ['code' => 'SZL'],
            [
                'name'      => 'Swazi Lilangeni',
                'type'      => 'fiat',
                'precision' => 2,
                'is_active' => true,
            ],
        );
    }

    /** @param array<int, User> $members */
    private function makeGroupThread(User $admin, array $members = []): Thread
    {
        $thread = Thread::create([
            'type' => 'group', 'name' => 'Test Group', 'created_by' => $admin->id,
        ]);

        ThreadParticipant::create([
            'thread_id' => $thread->id, 'user_id' => $admin->id,
            'role'      => 'admin', 'joined_at' => now(),
        ]);

        foreach ($members as $member) {
            ThreadParticipant::create([
                'thread_id' => $thread->id, 'user_id' => $member->id,
                'role'      => 'member', 'joined_at' => now(),
            ]);
        }

        return $thread;
    }

    private function makePocket(Thread $thread, User $creator, float $target = 5000): GroupPocket
    {
        return GroupPocket::create([
            'thread_id'     => $thread->id,
            'created_by'    => $creator->id,
            'name'          => 'Test Pocket',
            'category'      => 'general',
            'color'         => '#6366F1',
            'target_amount' => $target,
        ]);
    }

    private function fundUser(User $user, float $amountMajor): void
    {
        $account = Account::factory()->create([
            'user_uuid' => $user->uuid,
            'frozen'    => false,
        ]);

        $asset = Asset::query()->where('code', 'SZL')->firstOrFail();

        AccountBalance::factory()
            ->forAccount($account)
            ->forAsset('SZL')
            ->withBalance($asset->toSmallestUnit($amountMajor))
            ->create();
    }

    #[Test]
    public function member_can_deposit_into_group_pocket(): void
    {
        $admin = User::factory()->create();
        $member = User::factory()->create();
        $thread = $this->makeGroupThread($admin, [$member]);
        $pocket = $this->makePocket($thread, $admin);

        $this->fundUser($member, 1000.00);

        Sanctum::actingAs($member, ['read', 'write']);

        $response = $this->postJson("/api/savings/group-pockets/{$pocket->id}/deposit", [
            'amount' => 500,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.pocket.current_amount', '500.00');

        $this->assertDatabaseHas('group_pockets', [
            'id'             => $pocket->id,
            'current_amount' => '500.00',
        ]);

        $this->assertDatabaseHas('group_pocket_contributions', [
            'group_pocket_id' => $pocket->id,
            'user_id'         => $member->id,
            'amount'          => '500.00',
        ]);
    }

    #[Test]
    public function deposit_is_rejected_when_it_would_exceed_regulatory_cap(): void
    {
        $admin = User::factory()->create();
        $thread = $this->makeGroupThread($admin);
        $pocket = $this->makePocket($thread, $admin, 200000);

        $pocket->update(['current_amount' => 99999.00]);

        $this->fundUser($admin, 10000.00);

        Sanctum::actingAs($admin, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/deposit", ['amount' => 2])
            ->assertStatus(422)
            ->assertJsonPath('message.0', 'This pocket has reached the regulatory maximum of E100,000');
    }

    #[Test]
    public function non_member_cannot_deposit(): void
    {
        $admin = User::factory()->create();
        $outsider = User::factory()->create();
        $thread = $this->makeGroupThread($admin);
        $pocket = $this->makePocket($thread, $admin);

        $this->fundUser($outsider, 1000.00);

        Sanctum::actingAs($outsider, ['read', 'write']);

        $this->postJson("/api/savings/group-pockets/{$pocket->id}/deposit", ['amount' => 100])
            ->assertStatus(403);
    }
}
