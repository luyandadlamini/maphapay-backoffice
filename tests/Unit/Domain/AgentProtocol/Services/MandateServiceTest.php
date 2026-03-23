<?php

declare(strict_types=1);

use App\Domain\AgentProtocol\DataObjects\CartMandate;
use App\Domain\AgentProtocol\DataObjects\IntentMandate;
use App\Domain\AgentProtocol\DataObjects\MandateResult;
use App\Domain\AgentProtocol\DataObjects\PaymentMandate;
use App\Domain\AgentProtocol\Enums\MandateStatus;
use App\Domain\AgentProtocol\Enums\MandateType;

describe('AP2 Mandate Data Objects', function (): void {
    it('creates CartMandate and serializes', function (): void {
        $cart = new CartMandate(
            items: [['name' => 'Widget', 'quantity' => 1, 'price_cents' => 1000, 'currency' => 'USD']],
            totalCents: 1000,
            currency: 'USD',
            merchantDid: 'did:finaegis:agent:merchant001',
            shoppingAgentDid: 'did:finaegis:agent:shopper001',
            cartId: 'cart_123',
        );

        $array = $cart->toArray();
        expect($array['total_cents'])->toBe(1000);
        expect($array['merchant_did'])->toBe('did:finaegis:agent:merchant001');
        expect($array['cart_id'])->toBe('cart_123');

        $restored = CartMandate::fromArray($array);
        expect($restored->totalCents)->toBe(1000);
        expect($restored->items)->toHaveCount(1);
    });

    it('creates IntentMandate and serializes', function (): void {
        $intent = new IntentMandate(
            intent: 'Buy coffee when price drops below $3',
            budgetCents: 500,
            currency: 'USD',
            delegatorDid: 'did:finaegis:agent:user001',
            agentDid: 'did:finaegis:agent:shopper001',
            constraints: ['max_price_cents' => 300],
            requiresRefundability: true,
        );

        $array = $intent->toArray();
        expect($array['intent'])->toContain('coffee');
        expect($array['budget_cents'])->toBe(500);
        expect($array['requires_refundability'])->toBeTrue();

        $restored = IntentMandate::fromArray($array);
        expect($restored->budgetCents)->toBe(500);
        expect($restored->requiresRefundability)->toBeTrue();
    });

    it('creates PaymentMandate and serializes', function (): void {
        $payment = new PaymentMandate(
            payeeDid: 'did:finaegis:agent:merchant001',
            amountCents: 2500,
            currency: 'USD',
            payerDid: 'did:finaegis:agent:payer001',
            paymentMethodPreferences: ['x402', 'mpp'],
            reference: 'Invoice #123',
        );

        $array = $payment->toArray();
        expect($array['amount_cents'])->toBe(2500);
        expect($array['payment_method_preferences'])->toBe(['x402', 'mpp']);

        $restored = PaymentMandate::fromArray($array);
        expect($restored->amountCents)->toBe(2500);
        expect($restored->reference)->toBe('Invoice #123');
    });

    it('creates MandateResult', function (): void {
        $result = new MandateResult(
            mandateId: 'uuid-123',
            status: MandateStatus::EXECUTED,
            paymentReferences: ['tx_ref_001'],
        );

        $array = $result->toArray();
        expect($array['mandate_id'])->toBe('uuid-123');
        expect($array['status'])->toBe('executed');
        expect($array['payment_references'])->toContain('tx_ref_001');
    });
});

describe('AP2 Mandate Enums', function (): void {
    it('has all mandate types', function (): void {
        expect(MandateType::cases())->toHaveCount(3);
        expect(MandateType::CART_MANDATE->requiresHumanPresence())->toBeTrue();
        expect(MandateType::INTENT_MANDATE->requiresHumanPresence())->toBeFalse();
        expect(MandateType::PAYMENT_MANDATE->requiresHumanPresence())->toBeFalse();
    });

    it('has all mandate statuses', function (): void {
        expect(MandateStatus::cases())->toHaveCount(8);
        expect(MandateStatus::COMPLETED->isTerminal())->toBeTrue();
        expect(MandateStatus::REVOKED->isTerminal())->toBeTrue();
        expect(MandateStatus::EXPIRED->isTerminal())->toBeTrue();
        expect(MandateStatus::ISSUED->isTerminal())->toBeFalse();
        expect(MandateStatus::ISSUED->isActive())->toBeTrue();
        expect(MandateStatus::ACCEPTED->isActive())->toBeTrue();
        expect(MandateStatus::DRAFT->isActive())->toBeFalse();
    });
});
