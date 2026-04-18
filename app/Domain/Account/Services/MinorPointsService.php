<?php
declare(strict_types=1);
namespace App\Domain\Account\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorPointsLedger;
use Illuminate\Validation\ValidationException;

class MinorPointsService
{
    public function getBalance(Account $minorAccount): int
    {
        return (int) MinorPointsLedger::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->sum('points');
    }

    public function award(
        Account $minorAccount,
        int $points,
        string $source,
        string $description,
        ?string $referenceId
    ): MinorPointsLedger {
        return MinorPointsLedger::create([
            'minor_account_uuid' => $minorAccount->uuid,
            'points'             => abs($points),
            'source'             => $source,
            'description'        => $description,
            'reference_id'       => $referenceId,
        ]);
    }

    public function deduct(
        Account $minorAccount,
        int $points,
        string $source,
        string $description,
        ?string $referenceId
    ): MinorPointsLedger {
        $balance = $this->getBalance($minorAccount);

        if ($points > $balance) {
            throw ValidationException::withMessages([
                'points' => ["Insufficient points balance. Current balance: {$balance}, requested: {$points}."],
            ]);
        }

        return MinorPointsLedger::create([
            'minor_account_uuid' => $minorAccount->uuid,
            'points'             => -abs($points),
            'source'             => $source,
            'description'        => $description,
            'reference_id'       => $referenceId,
        ]);
    }

    /**
     * Check whether the minor account has newly crossed a saving milestone
     * and award points if so. Safe to call after every successful transfer.
     * Uses the ledger reference_id to prevent double-awarding the same milestone.
     *
     * @param  Account $minorAccount  The minor account that received/saved funds.
     * @param  string  $totalSavedSzl Major-unit decimal string of cumulative saved amount.
     */
    public function checkAndAwardSavingMilestones(Account $minorAccount, string $totalSavedSzl): void
    {
        $milestones = [
            '100_szl'  => ['threshold' => 100,  'points' => 50],
            '500_szl'  => ['threshold' => 500,  'points' => 200],
            '1000_szl' => ['threshold' => 1000, 'points' => 500],
        ];

        $total = (float) $totalSavedSzl;
        $alreadyAwarded = MinorPointsLedger::query()
            ->where('minor_account_uuid', $minorAccount->uuid)
            ->where('source', 'saving_milestone')
            ->whereNotNull('reference_id')
            ->pluck('reference_id')
            ->all();

        foreach ($milestones as $key => $milestone) {
            if ($total >= $milestone['threshold'] && ! in_array($key, $alreadyAwarded, true)) {
                $this->award(
                    $minorAccount,
                    $milestone['points'],
                    'saving_milestone',
                    "Reached {$milestone['threshold']} SZL saved",
                    $key
                );
            }
        }
    }
}
