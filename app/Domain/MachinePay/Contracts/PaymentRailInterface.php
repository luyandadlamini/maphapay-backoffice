<?php

declare(strict_types=1);

namespace App\Domain\MachinePay\Contracts;

use App\Domain\MachinePay\DataObjects\MppCredential;
use App\Domain\MachinePay\DataObjects\MppReceipt;
use App\Domain\MachinePay\Enums\PaymentRail;

/**
 * Interface for payment rail adapters.
 *
 * Each rail (Stripe, Tempo, Lightning, Card) implements this
 * interface to process, verify, and refund MPP payments.
 */
interface PaymentRailInterface
{
    /**
     * Process a payment credential and return a settlement receipt.
     *
     * @param MppCredential         $credential The client's payment proof.
     * @param array<string, mixed>  $context    Rail-specific context (amounts, merchant info).
     */
    public function processPayment(MppCredential $credential, array $context = []): MppReceipt;

    /**
     * Verify a payment credential without settling.
     *
     * @param MppCredential $credential The client's payment proof.
     */
    public function verifyPayment(MppCredential $credential): bool;

    /**
     * Refund a settled payment.
     *
     * @param string $settlementReference The settlement reference from the receipt.
     * @param int    $amountCents         Amount to refund in smallest currency unit.
     */
    public function refund(string $settlementReference, int $amountCents): bool;

    /**
     * Get the payment rail identifier.
     */
    public function getRailIdentifier(): PaymentRail;

    /**
     * Check whether this rail is currently available (configured + healthy).
     */
    public function isAvailable(): bool;
}
