<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\DataObjects\Account as AccountDTO;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\AccountProfileMerchant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class MerchantAccountService
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountService $accountService,
    ) {
    }

    /**
     * @return array{account: Account, profile: AccountProfileMerchant, membership: AccountMembership}
     */
    public function createForUser(User $user, string $tenantId, array $profileData): array
    {
        $existingMerchant = AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_type', 'merchant')
            ->active()
            ->exists();

        if ($existingMerchant) {
            throw ValidationException::withMessages([
                'merchant' => ['You already have a merchant account.'],
            ]);
        }

        $account = null;
        $profile = null;

        try {
            // Tenant DB writes — wrapped in a single tenant connection transaction
            DB::transaction(function () use ($user, $profileData, &$account, &$profile): void {
                // Create account via the event-sourced path (records to LedgerAggregate)
                $accountUuid = $this->accountService->createDirect(
                    new AccountDTO(
                        name: $profileData['trade_name'],
                        userUuid: $user->uuid,
                    )
                );

                $account = Account::query()->where('uuid', $accountUuid)->firstOrFail();

                // Set merchant-specific fields not covered by the base AccountDTO
                $account->update([
                    'display_name'      => $profileData['trade_name'],
                    'type'              => 'merchant',
                    'verification_tier' => 'unverified',
                    'capabilities'      => ['can_receive_payments'],
                ]);

                $account = $account->fresh();

                $profile = AccountProfileMerchant::query()->create([
                    'account_uuid'      => $account->uuid,
                    'trade_name'        => $profileData['trade_name'],
                    'merchant_category' => $profileData['merchant_category'],
                    'classification'    => $profileData['classification'],
                    'settlement_method' => $profileData['settlement_method'],
                    'location'          => $profileData['location'] ?? null,
                    'description'       => $profileData['description'] ?? null,
                    'qr_code_payload'   => $profileData['qr_code_payload'] ?? null,
                ]);
            });

            // Central DB write — outside tenant transaction (different connection)
            $membership = $this->membershipService->createOwnerMembership(
                $user,
                $tenantId,
                $account,
                $profileData['trade_name'],
                [
                    'verification_tier' => 'unverified',
                    'capabilities'      => ['can_receive_payments'],
                ],
            );

            // Audit log — written in the tenant DB context (already initialized)
            AccountAuditLog::create([
                'account_uuid'    => $account->uuid,
                'actor_user_uuid' => $user->uuid,
                'action'          => 'account.created',
                'metadata'        => ['trade_name' => $profileData['trade_name'], 'type' => 'merchant'],
                'created_at'      => now(),
            ]);

            return [
                'account'    => $account->fresh(),
                'profile'    => $profile->fresh(),
                'membership' => $membership,
            ];
        } catch (Throwable $e) {
            // The DB::transaction() above already rolled back tenant writes if they failed.
            // If tenant writes committed but the central write failed, clean up manually.
            if ($account !== null) {
                try {
                    $profile?->forceDelete();
                    $account->forceDelete();
                } catch (Throwable $cleanupException) {
                    Log::error('MerchantAccountService: cleanup failed after creation error', [
                        'user_uuid'      => $user->uuid,
                        'account_uuid'   => $account->uuid ?? null,
                        'creation_error' => $e->getMessage(),
                        'cleanup_error'  => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }
}
