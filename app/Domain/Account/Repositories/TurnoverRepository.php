<?php

declare(strict_types=1);

namespace App\Domain\Account\Repositories;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\Turnover;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

final class TurnoverRepository
{
    public function __construct(
        protected Turnover $turnover
    ) {
    }

    /**
     * @param  string  $accountUuid
     */
    public function findByAccountAndDate(
        Account $account,
        DateTimeInterface $date
    ): ?Turnover {
        return Turnover::where('account_uuid', $account->uuid)
            ->where('date', $date->toDateString())
            ->first();
    }

    public function incrementForDateById(
        DateTimeInterface $date,
        string $accountUuid,
        int $amount
    ): Turnover {
        $dateString = $date->toDateString();

        // Use raw SQL for atomic upsert operation
        if (config('database.default') === 'sqlite') {
            // SQLite specific implementation
            DB::statement(
                'INSERT INTO turnovers (account_uuid, date, count, amount, debit, credit, created_at, updated_at) 
                VALUES (?, ?, 1, ?, ?, ?, ?, ?) 
                ON CONFLICT(account_uuid, date) 
                DO UPDATE SET 
                    count = count + 1,
                    amount = amount + excluded.amount,
                    debit = debit + excluded.debit,
                    credit = credit + excluded.credit,
                    updated_at = excluded.updated_at',
                [
                    $accountUuid,
                    $dateString,
                    $amount,
                    $amount < 0 ? abs($amount) : 0,
                    $amount > 0 ? $amount : 0,
                    now(),
                    now(),
                ]
            );
        } else {
            // MySQL specific implementation
            DB::statement(
                'INSERT INTO turnovers (account_uuid, date, count, amount, debit, credit, created_at, updated_at) 
                VALUES (?, ?, 1, ?, ?, ?, ?, ?) 
                ON DUPLICATE KEY UPDATE 
                    count = count + 1,
                    amount = amount + VALUES(amount),
                    debit = debit + VALUES(debit),
                    credit = credit + VALUES(credit),
                    updated_at = VALUES(updated_at)',
                [
                    $accountUuid,
                    $dateString,
                    $amount,
                    $amount < 0 ? abs($amount) : 0,
                    $amount > 0 ? $amount : 0,
                    now(),
                    now(),
                ]
            );
        }

        // Fetch and return the updated record
        return Turnover::where('account_uuid', $accountUuid)
            ->where('date', $dateString)
            ->firstOrFail();
    }

    protected function updateTurnover(Turnover $turnover, int $amount): Turnover
    {
        $turnover->count += 1;
        $turnover->amount += $amount;

        // Update debit/credit fields for proper accounting
        if ($amount > 0) {
            $turnover->credit += $amount;
        } else {
            $turnover->debit += abs($amount);
        }

        // Save the changes in a single query
        $turnover->save();

        return $turnover;
    }
}
