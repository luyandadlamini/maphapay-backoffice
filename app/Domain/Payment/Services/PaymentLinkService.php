<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Models\MoneyRequest;
use Illuminate\Support\Str;

class PaymentLinkService
{
    private const TOKEN_LENGTH = 12;

    private const LINK_EXPIRY_DAYS = 10;

    public function generatePaymentToken(): string
    {
        return Str::random(self::TOKEN_LENGTH);
    }

    public function buildDynamicLink(string $token): string
    {
        return 'https://pay.maphapay.com/r/' . $token;
    }

    public function assignPaymentToken(MoneyRequest $moneyRequest): MoneyRequest
    {
        $token = $this->generatePaymentToken();
        $expiresAt = now()->addDays(self::LINK_EXPIRY_DAYS);

        $moneyRequest->update([
            'payment_token' => $token,
            'expires_at'    => $expiresAt,
        ]);

        return $moneyRequest->fresh() ?? $moneyRequest;
    }

    public function isValidPaymentToken(?string $token): bool
    {
        return $this->resolveValidMoneyRequest($token) !== null;
    }

    /** @return array<string, mixed>|null */
    public function getPaymentLinkData(string $token): ?array
    {
        $moneyRequest = $this->resolveValidMoneyRequest($token);

        if (! $moneyRequest) {
            return null;
        }

        /** @var \App\Models\User|null $requester */
        $requester = $moneyRequest->requester;

        return [
            'display_name' => $requester !== null ? $requester->name : 'Unknown',
            'avatar_url'   => $requester !== null ? $requester->getAttribute('profile_photo_url') : null,
            'amount'       => $moneyRequest->amount,
            'note'         => $moneyRequest->note,
            'currency'     => 'SZL',
            'asset_code'   => $moneyRequest->asset_code,
        ];
    }

    public function markAsPaid(MoneyRequest $moneyRequest): MoneyRequest
    {
        $moneyRequest->update([
            'status'  => MoneyRequest::STATUS_FULFILLED,
            'paid_at' => now(),
        ]);

        return $moneyRequest->fresh() ?? $moneyRequest;
    }

    public function resolveValidMoneyRequest(?string $token): ?MoneyRequest
    {
        if (! $token) {
            return null;
        }

        return MoneyRequest::query()
            ->where('payment_token', $token)
            ->where('expires_at', '>', now())
            ->where('status', MoneyRequest::STATUS_PENDING)
            ->whereNull('paid_at')
            ->first();
    }
}
