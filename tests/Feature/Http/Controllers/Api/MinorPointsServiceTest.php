<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Services\MinorPointsService;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MinorPointsServiceTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    protected function connectionsToTransact(): array
    {
    return ['mysql', 'central'];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
    return false;
    }

    private MinorPointsService $service;

    private Account $minorAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MinorPointsService::class);
        $user = User::factory()->create();
        /** @var Account $account */
        $account = Account::factory()->create([
            'user_uuid'        => $user->uuid,
            'type'             => 'minor',
            'tier'             => 'grow',
            'permission_level' => 3,
        ]);
        $this->minorAccount = $account;
    }

    #[Test]
    public function it_awards_points_and_creates_ledger_entry(): void
    {
        $entry = $this->service->award(
            $this->minorAccount,
            50,
            'saving_milestone',
            'Reached 100 SZL saved',
            '100_szl'
        );

        $this->assertSame(50, $entry->points);
        $this->assertSame('saving_milestone', $entry->source);
        $this->assertSame('100_szl', $entry->reference_id);
        $this->assertDatabaseHas('minor_points_ledger', [
            'minor_account_uuid' => $this->minorAccount->uuid,
            'points'             => 50,
            'source'             => 'saving_milestone',
            'reference_id'       => '100_szl',
        ]);
    }

    #[Test]
    public function it_returns_correct_balance_as_sum_of_ledger(): void
    {
        $this->service->award($this->minorAccount, 100, 'level_unlock', 'Level 3 unlocked', null);
        $this->service->award($this->minorAccount, 50, 'saving_milestone', '100 SZL saved', '100_szl');
        $this->service->deduct($this->minorAccount, 30, 'redemption', 'Airtime 15 SZL', 'redemption-uuid-1');

        $this->assertSame(120, $this->service->getBalance($this->minorAccount));
    }

    #[Test]
    public function it_throws_when_deducting_more_than_balance(): void
    {
        $this->service->award($this->minorAccount, 50, 'saving_milestone', 'Milestone', '100_szl');

        $this->expectException(ValidationException::class);
        $this->service->deduct($this->minorAccount, 100, 'redemption', 'Too many points', 'ref-1');
    }

    #[Test]
    public function it_returns_zero_balance_when_no_ledger_entries_exist(): void
    {
        $this->assertSame(0, $this->service->getBalance($this->minorAccount));
    }
}
