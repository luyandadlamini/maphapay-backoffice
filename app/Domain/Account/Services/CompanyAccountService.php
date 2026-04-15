<?php

declare(strict_types=1);

namespace App\Domain\Account\Services;

use App\Domain\Account\DataObjects\Account as AccountDTO;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\AccountProfileCompany;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class CompanyAccountService
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountService $accountService,
    ) {}

    /**
     * @return array{account: Account, profile: AccountProfileCompany, membership: AccountMembership}
     */
    public function createForUser(User $user, string $tenantId, array $profileData): array
    {
        $existingCompany = AccountMembership::query()
            ->forUser($user->uuid)
            ->where('account_type', 'company')
            ->active()
            ->exists();

        if ($existingCompany) {
            throw ValidationException::withMessages([
                'company' => ['You already have a company account.'],
            ]);
        }

        // Check for duplicate registration number if provided
        if (!empty($profileData['registration_number'])) {
            $existingByReg = AccountProfileCompany::query()
                ->where('registration_number', $profileData['registration_number'])
                ->exists();

            if ($existingByReg) {
                throw ValidationException::withMessages([
                    'registration_number' => ['A company with this registration number already exists.'],
                ]);
            }
        }

        // Check for duplicate TIN if provided
        if (!empty($profileData['tin_number'])) {
            $existingByTin = AccountProfileCompany::query()
                ->where('tin_number', $profileData['tin_number'])
                ->exists();

            if ($existingByTin) {
                throw ValidationException::withMessages([
                    'tin_number' => ['A company with this TIN already exists.'],
                ]);
            }
        }

        $account = null;
        $profile = null;

        try {
            DB::transaction(function () use ($user, $profileData, &$account, &$profile): void {
                $accountUuid = $this->accountService->createDirect(
                    new AccountDTO(
                        name: $profileData['company_name'],
                        userUuid: $user->uuid,
                    )
                );

                $account = Account::query()->where('uuid', $accountUuid)->firstOrFail();

                $account->update([
                    'display_name' => $profileData['company_name'],
                    'type' => 'company',
                    'verification_tier' => 'unverified',
                    'capabilities' => ['can_receive_payments'],
                ]);

                $account = $account->fresh();

                $profile = AccountProfileCompany::query()->create([
                    'account_uuid' => $account->uuid,
                    'company_name' => $profileData['company_name'],
                    'business_type' => $profileData['business_type'],
                    'registration_number' => $profileData['registration_number'] ?? null,
                    'tin_number' => $profileData['tin_number'] ?? null,
                    'industry' => $profileData['industry'] ?? null,
                    'company_size' => $profileData['company_size'] ?? null,
                    'settlement_method' => $profileData['settlement_method'],
                    'address' => $profileData['address'] ?? null,
                    'description' => $profileData['description'] ?? null,
                ]);
            });

            $membership = $this->membershipService->createOwnerMembership(
                $user,
                $tenantId,
                $account,
                $profileData['company_name'],
                [
                    'account_type' => 'company',
                    'verification_tier' => 'unverified',
                    'capabilities' => ['can_receive_payments'],
                ],
            );

            AccountAuditLog::create([
                'account_uuid' => $account->uuid,
                'actor_user_uuid' => $user->uuid,
                'action' => 'account.created',
                'metadata' => ['company_name' => $profileData['company_name'], 'type' => 'company'],
                'created_at' => now(),
            ]);

            return [
                'account' => $account->fresh(),
                'profile' => $profile->fresh(),
                'membership' => $membership,
            ];
        } catch (Throwable $e) {
            if ($account !== null) {
                try {
                    $profile?->forceDelete();
                    $account->forceDelete();
                } catch (Throwable $cleanupException) {
                    Log::error('CompanyAccountService: cleanup failed after creation error', [
                        'user_uuid' => $user->uuid,
                        'account_uuid' => $account->uuid ?? null,
                        'creation_error' => $e->getMessage(),
                        'cleanup_error' => $cleanupException->getMessage(),
                    ]);
                }
            }

            throw $e;
        }
    }
}