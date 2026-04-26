<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorCardLimit;
use App\Domain\Account\Models\MinorCardRequest;
use App\Domain\CardIssuance\Enums\CardNetwork;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Services\CardProvisioningService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MinorCardService
{
    public function __construct(
        private readonly CardProvisioningService $cardProvisioning,
        private readonly MinorAccountAccessService $accessService,
    ) {
    }

    public function createCardFromRequest(MinorCardRequest $request): Card
    {
        return DB::transaction(function () use ($request): Card {
            $minor = Account::where('uuid', $request->minor_account_uuid)->firstOrFail();
            $limits = $this->resolveLimits($request, $minor);

            $network = $request->requested_network === 'mastercard'
                ? CardNetwork::MASTERCARD
                : CardNetwork::VISA;

            $card = $this->cardProvisioning->createCard(
                userId: $minor->user_uuid,
                cardholderName: $minor->name,
                metadata: [
                    'minor_account_uuid' => $minor->uuid,
                    'card_request_id'    => $request->uuid,
                    'tier'               => 'rise',
                ],
                network: $network,
            );

            $this->cardProvisioning->updateSpendingLimits($card->cardToken, $limits);

            /** @var Card $persistedCard */
            $persistedCard = Card::where('issuer_card_token', $card->cardToken)->firstOrFail();
            $persistedCard->update(['minor_account_uuid' => $minor->uuid]);

            $request->update(['status' => MinorCardConstants::STATUS_CARD_CREATED]);

            return $persistedCard;
        });
    }

    public function freezeCard(User $guardian, Card $card): Card
    {
        $minor = $card->minorAccount;
        if (! $minor instanceof Account || ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
            throw new InvalidArgumentException('Only guardians can freeze a minor card');
        }

        $this->cardProvisioning->freezeCard($card->issuer_card_token);

        return $card->refresh();
    }

    public function unfreezeCard(User $guardian, Card $card): Card
    {
        $minor = $card->minorAccount;
        if (! $minor instanceof Account || ! $this->accessService->hasGuardianAccess($guardian, $minor)) {
            throw new InvalidArgumentException('Only guardians can unfreeze a minor card');
        }

        $this->cardProvisioning->unfreezeCard($card->issuer_card_token);

        return $card->refresh();
    }

    /**
     * @return array<int, mixed>
     */
    public function listMinorCards(Account $minor): array
    {
        $tokens = Card::where('minor_account_uuid', $minor->uuid)
            ->whereNotIn('status', ['cancelled'])
            ->pluck('issuer_card_token')
            ->toArray();

        return array_map(
            fn ($token) => $this->cardProvisioning->getCard($token),
            $tokens
        );
    }

    /**
     * @return array{daily: float, monthly: float, single_transaction: float}
     */
    private function resolveLimits(MinorCardRequest $request, Account $minor): array
    {
        $limitRecord = MinorCardLimit::where('minor_account_uuid', $minor->uuid)->first();

        $defaultDaily = (string) config('minor_family.card_limits.daily_default', '2000.00');
        $defaultMonthly = (string) config('minor_family.card_limits.monthly_default', '10000.00');
        $defaultSingle = (string) config('minor_family.card_limits.single_transaction_default', '1500.00');

        $requestedDaily = $request->requested_daily_limit ?? $defaultDaily;
        $requestedMonthly = $request->requested_monthly_limit ?? $defaultMonthly;
        $requestedSingle = $request->requested_single_limit ?? $defaultSingle;

        $accountDaily = $limitRecord instanceof MinorCardLimit ? $limitRecord->daily_limit : $defaultDaily;
        $accountMonthly = $limitRecord instanceof MinorCardLimit ? $limitRecord->monthly_limit : $defaultMonthly;
        $accountSingle = $limitRecord instanceof MinorCardLimit ? $limitRecord->single_transaction_limit : $defaultSingle;

        return [
            'daily'              => min((float) $requestedDaily, (float) $accountDaily),
            'monthly'            => min((float) $requestedMonthly, (float) $accountMonthly),
            'single_transaction' => min((float) $requestedSingle, (float) $accountSingle),
        ];
    }
}
