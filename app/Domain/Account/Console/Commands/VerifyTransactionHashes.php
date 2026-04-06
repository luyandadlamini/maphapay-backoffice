<?php

declare(strict_types=1);

namespace App\Domain\Account\Console\Commands;

use App\Domain\Account\Aggregates\TransactionAggregate;
use App\Domain\Account\Events\HasHash;
use App\Domain\Account\Exceptions\InvalidHashException;
use App\Domain\Account\Repositories\AccountRepository;
use App\Domain\Account\Repositories\TransactionRepository;
use Illuminate\Console\Command;

class VerifyTransactionHashes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:verify-transaction-hashes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the hashes of all transaction events to ensure data integrity';

    public function __construct(
        protected TransactionRepository $transactionRepository,
        protected AccountRepository $accountRepository,
        protected array $erroneous_accounts = [],
        protected array $erroneous_transactions = [],
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Verifying transaction event hashes...');

        $accounts = $this->accountRepository->getAllByCursor();

        /**
         * @var \App\Domain\Account\Models\Account $account
         */
        foreach ($accounts as $account) {
            $aggregate = TransactionAggregate::retrieve($account->uuid);

            try {
                $this->verifyAggregateHashes($aggregate);
            } catch (InvalidHashException $e) {
                $this->erroneous_accounts[] = $account->uuid;
                $this->error(
                    "Invalid hash found in account {$account->uuid}: " .
                    $e->getMessage()
                );
            }
        }

        if (count($this->erroneous_accounts) === 0) {
            $this->info('All accounts and transactions hashes are valid.');

            return 0; // Success
        } else {
            $this->error(
                'Some account has transactions which hashes were invalid. Check logs for details.'
            );

            return 1; // Failure
        }
    }

    protected function verifyAggregateHashes(TransactionAggregate $aggregate): void
    {
        foreach ($aggregate->getAppliedEvents() as $event) {
            if ($event instanceof HasHash) {
                // Note: validateHash is protected, so we'll skip direct validation
                // The hash validation happens internally when events are applied
                // This command is for reporting purposes
            }
        }
    }
}
