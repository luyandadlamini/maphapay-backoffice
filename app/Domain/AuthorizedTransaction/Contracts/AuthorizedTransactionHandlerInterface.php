<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Contracts;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;
use Exception;

/**
 * Contract for remark-specific finalize handlers.
 *
 * A handler receives the verified AuthorizedTransaction, executes the
 * wallet mutation (via WalletOperationsService), and returns an array
 * that is stored as the transaction's result and returned to mobile.
 */
interface AuthorizedTransactionHandlerInterface
{
    /**
     * Execute the money-moving side effect for this authorized transaction.
     *
     * Called exactly once — the AuthorizedTransactionManager guarantees
     * the transaction is in pending status before dispatch and marks it
     * completed atomically after this method returns successfully.
     *
     * @return array<string, mixed> Response data returned to the mobile client.
     * @throws Exception On wallet workflow failure (manager will mark failed).
     */
    public function handle(AuthorizedTransaction $transaction): array;
}
