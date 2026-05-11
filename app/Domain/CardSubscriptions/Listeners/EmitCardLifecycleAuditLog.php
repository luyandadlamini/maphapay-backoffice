<?php

declare(strict_types=1);

namespace App\Domain\CardSubscriptions\Listeners;

use App\Domain\CardIssuance\Events\AuthorizationApproved;
use App\Domain\CardIssuance\Events\AuthorizationDeclined;
use App\Domain\CardIssuance\Events\CardProvisioned;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardSubscriptions\Services\CardAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

class EmitCardLifecycleAuditLog implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly CardAuditService $audit,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(CardProvisioned|AuthorizationApproved|AuthorizationDeclined $event): void
    {
        if ($event instanceof CardProvisioned) {
            $card = Card::query()->where('issuer_card_token', $event->cardToken)->first();

            if ($card === null) {
                return;
            }

            $this->audit->recordCardEvent('processor.wallet_provision_requested', $card, null, [
                'wallet_type' => $event->walletType->value,
                'device_id'   => $event->deviceId,
            ]);

            return;
        }

        if ($event instanceof AuthorizationApproved) {
            $card = Card::query()->where('issuer_card_token', $event->cardToken)->first();

            if ($card === null) {
                return;
            }

            $this->audit->recordCardEvent('card.auth_approved', $card, null, [
                'authorization_id' => $event->authorizationId,
                'amount'           => (string) $event->amount,
                'currency'         => $event->currency,
                'merchant'         => $event->merchantName,
            ]);

            return;
        }

        $card = Card::query()->where('issuer_card_token', $event->cardToken)->first();

        if ($card === null) {
            return;
        }

        $this->audit->recordCardEvent('card.auth_declined', $card, null, [
            'authorization_id' => $event->authorizationId,
            'amount'           => (string) $event->amount,
            'currency'         => $event->currency,
            'reason'           => $event->reason->value,
            'merchant'         => $event->merchantName,
        ]);
    }
}
