<?php

declare(strict_types=1);

namespace Tests\Domain\Account\Projectors;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\DataObjects\Money;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Turnover;
use App\Domain\Account\Repositories\TurnoverRepository;
use App\Domain\Account\Utils\ValidatesHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TurnoverProjectorTest extends TestCase
{
    use ValidatesHash;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a unique account for each test to avoid conflicts
        $this->account = Account::factory()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'test-turnover-' . Str::random(8),
        ]);
    }

    #[Test]
    public function test_calculate_today_turnover(): void
    {
        $this->resetHash();
        $date = Carbon::createFromDate(2024, 1, 1);
        Carbon::setTestNow($date);

        // Clear any existing turnovers for this account and date to avoid conflicts
        Turnover::where('account_uuid', $this->account->uuid)
            ->where('date', $date->toDateString())
            ->delete();

        $turnover = $this->getTurnoverForDate($date);
        $amount = $turnover->amount ?? 0;
        $count = $turnover->count ?? 0;

        $this->performTransactions([
            ['credit', 10],
            ['debit', 5],
            ['credit', 10],
        ]);

        $turnover = $this->getTurnoverForDate($date);

        $this->assertNotNull($turnover);
        $this->assertEquals($count + 3, $turnover->count);
        $this->assertEquals($amount + 15, $turnover->amount);

        Carbon::setTestNow();
    }

    #[Test]
    public function test_calculate_tomorrow_turnover(): void
    {
        $this->resetHash();
        $date1 = Carbon::createFromDate(2024, 1, 1);
        Carbon::setTestNow($date1);

        // Clear any existing turnovers for this account and date to avoid conflicts
        Turnover::where('account_uuid', $this->account->uuid)->delete();

        // Simulate yesterday's event
        $this->performTransactions([
            ['credit', 10],
        ]);

        $date2 = Carbon::createFromDate(2024, 1, 2);
        Carbon::setTestNow($date2);

        $turnover = $this->getTurnoverForDate($date2);
        $amount = $turnover->amount ?? 0;
        $count = $turnover->count ?? 0;

        $this->performTransactions([
            ['credit', 10],
        ]);

        $turnover = $this->getTurnoverForDate($date2);

        $this->assertNotNull($turnover);
        $this->assertEquals($count + 1, $turnover->count);
        $this->assertEquals($amount + 10, $turnover->amount);

        Carbon::setTestNow();
    }

    private function getTurnoverForDate(Carbon $date): ?Turnover
    {
        return app(TurnoverRepository::class)->findByAccountAndDate($this->account, $date);
    }

    private function performTransactions(array $transactions): void
    {
        foreach ($transactions as [$type, $amount]) {
            // Create a new aggregate instance for each transaction
            // to ensure events are persisted one by one
            $aggregate = TransactionAggregate::retrieve($this->account->uuid);
            $aggregate->$type($this->money($amount));
            $aggregate->persist();
        }
    }

    private function money(int $amount): Money
    {
        return new Money($amount);
    }
}
