<?php

declare(strict_types=1);

namespace App\Domain\AuthorizedTransaction\Events;

use App\Domain\AuthorizedTransaction\Models\AuthorizedTransaction;

/**
 * Fired after the atomic status flip + handler execution succeed inside
 * AuthorizedTransactionManager::finalizeAtomically. Listeners can rely on
 * the wallet posting having been written to the ledger by this point.
 *
 * Because the manager flips status via a raw query-builder UPDATE (for
 * concurrent-claim safety), Eloquent observers on AuthorizedTransaction
 * never see the transition — this event is the supported hook.
 */
final class AuthorizedTransactionFinalized
{
    public function __construct(
        public readonly AuthorizedTransaction $transaction,
    ) {}
}
