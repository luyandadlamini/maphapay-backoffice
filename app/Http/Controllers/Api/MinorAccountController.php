<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountAuditLog;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountMembershipService;
use App\Domain\User\Models\UserProfile;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Policies\AccountPolicy;
use App\Rules\NoControlCharacters;
use App\Rules\NoSqlInjection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MinorAccountController extends Controller
{
    public function __construct(
        private readonly AccountMembershipService $membershipService,
        private readonly AccountPolicy $accountPolicy,
    ) {
    }

    /**
     * Create a minor account for a child (6-17 years old).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'photo_id_path' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var User $user */
        $user = $request->user();

        abort_unless($this->accountPolicy->createMinor($user), 403);

        $parentMembership = AccountMembership::query()
            ->forUser($user->uuid)
            ->active()
            ->where('role', 'owner')
            ->where('account_type', 'personal')
            ->first();

        if ($parentMembership === null) {
            return response()->json([
                'success' => false,
                'message' => 'A personal account is required before creating a minor account.',
            ], 403);
        }

        $tenantId = (string) $parentMembership->tenant_id;

        // Calculate age and validate (6-17 years old)
        $dateOfBirth = Carbon::parse((string) $validated['date_of_birth'])->startOfDay();
        $age = (int) floor($dateOfBirth->diffInYears(now(), true));

        $ageMin = config('minor_family.age_min', 6);
        $ageMax = config('minor_family.age_max', 17);

        if ($age < $ageMin || $age > $ageMax) {
            return response()->json([
                'success' => false,
                'errors'  => [
                    'date_of_birth' => ["Child must be between {$ageMin} and {$ageMax} years old."],
                ],
            ], 422);
        }

        // Determine tier: grow or rise
        $tier = $age <= config('minor_family.tier_grow_max_age', 12) ? 'grow' : 'rise';

        // Determine permission level based on age
        $permissionLevel = $this->getPermissionLevel($age);

        try {
            $sanitizedName = strip_tags($validated['name']);
            $sanitizedName = htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8');
            $sanitizedName = (string) preg_replace('/javascript:/i', '', $sanitizedName);
            $sanitizedName = (string) preg_replace('/data:/i', '', $sanitizedName);
            $sanitizedName = (string) preg_replace('/vbscript:/i', '', $sanitizedName);
            $sanitizedName = trim($sanitizedName);
            $child = User::query()->create([
                'name'     => $sanitizedName,
                'email'    => null,
                'password' => Str::password(32),
            ]);

            UserProfile::query()->updateOrCreate(
                ['user_id' => $child->id],
                [
                    'email'         => $child->email ?? sprintf('%s@minor.local', $child->uuid),
                    'first_name'    => $sanitizedName,
                    'status'        => 'active',
                    'date_of_birth' => $dateOfBirth->toDateString(),
                    'is_verified'   => false,
                ],
            );

            $account = Account::create([
                'user_uuid'         => $child->uuid,
                'parent_account_id' => $parentMembership->account_uuid,
                'name'              => $sanitizedName,
                'type'              => 'minor',
                'tier'              => $tier,
                'permission_level'  => $permissionLevel,
            ]);

            $membership = $this->membershipService->createGuardianMembership($user, $tenantId, $account);

            return response()->json([
                'success' => true,
                'data'    => [
                    'account' => [
                        'uuid'              => $account->uuid,
                        'account_type'      => $account->type,
                        'name'              => $account->name,
                        'account_tier'      => $account->tier,
                        'permission_level'  => $account->permission_level,
                        'parent_account_id' => $account->parent_account_id,
                    ],
                    'membership' => [
                        'account_uuid' => $membership->account_uuid,
                        'user_uuid'    => $membership->user_uuid,
                        'role'         => $membership->role,
                        'status'       => $membership->status,
                        'account_type' => $membership->account_type,
                    ],
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('MinorAccountController: store failed', [
                'user_uuid' => $user->uuid,
                'error'     => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create minor account. Please try again.',
            ], 500);
        }
    }

    public function updatePermissionLevel(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $validated = $request->validate([
            'permission_level' => ['required', 'integer', 'min:' . config('minor_family.permission_level_min', 1), 'max:' . config('minor_family.permission_level_max_rise', 7)],
        ]);

        $previousLevel = (int) $account->permission_level;
        $newPermissionLevel = (int) $validated['permission_level'];
        $currentPermissionLevel = (int) ($account->permission_level ?? 0);

        if ($newPermissionLevel < $currentPermissionLevel) {
            return response()->json([
                'message' => 'The permission level cannot be lower than the current level.',
                'errors'  => [
                    'permission_level' => ['The permission level cannot be lower than the current level.'],
                ],
            ], 422);
        }

        if ($account->tier === 'grow' && $newPermissionLevel > config('minor_family.permission_level_max_grow', 4)) {
            return response()->json([
                'message' => 'Grow tier accounts cannot exceed permission level ' . config('minor_family.permission_level_max_grow', 4) . '.',
                'errors'  => [
                    'permission_level' => ['Grow tier accounts cannot exceed permission level ' . config('minor_family.permission_level_max_grow', 4) . '.'],
                ],
            ], 422);
        }

        if ($account->tier === 'rise' && $newPermissionLevel > config('minor_family.permission_level_max_rise', 7)) {
            return response()->json([
                'message' => 'Rise tier accounts cannot exceed permission level ' . config('minor_family.permission_level_max_rise', 7) . '.',
                'errors'  => [
                    'permission_level' => ['Rise tier accounts cannot exceed permission level ' . config('minor_family.permission_level_max_rise', 7) . '.'],
                ],
            ], 422);
        }

        $account->forceFill([
            'permission_level' => $newPermissionLevel,
        ])->save();

        AccountAuditLog::create([
            'account_uuid'    => $account->uuid,
            'actor_user_uuid' => $user->uuid,
            'action'          => 'permission_level_changed',
            'metadata'        => [
                'old_value' => $previousLevel,
                'new_value' => $newPermissionLevel,
                'reason'    => $request->string('reason', 'Guardian updated permission level')->toString(),
            ],
            'created_at' => now(),
        ]);

        // Award level-unlock bonus points when guardian advances the child's level
        if ($newPermissionLevel > $previousLevel) {
            try {
                app(\App\Domain\Account\Services\MinorPointsService::class)->award(
                    $account,
                    100,
                    'level_unlock',
                    "Unlocked Level {$newPermissionLevel}",
                    "level_{$newPermissionLevel}"
                );
            } catch (Throwable) {
                // Points are a bonus feature; never fail the level update.
            }
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'uuid'              => $account->uuid,
                'account_type'      => $account->type,
                'account_tier'      => $account->tier,
                'permission_level'  => $account->permission_level,
                'parent_account_id' => $account->parent_account_id,
            ],
        ]);
    }

    /**
     * PUT /api/accounts/minor/{uuid}/emergency-allowance.
     *
     * Guardian pre-sets an emergency reserve (SZL integer).
     * Setting to 0 disables emergency allowance.
     */
    public function setEmergencyAllowance(Request $request, string $uuid): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $account = Account::query()->where('uuid', $uuid)->firstOrFail();

        abort_unless($this->accountPolicy->updateMinor($user, $account), 403);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:0', 'max:' . config('minor_family.emergency_allowance_max', 100000)],
        ]);

        $amount = (int) $validated['amount'];

        $account->forceFill([
            'emergency_allowance_amount'  => $amount > 0 ? $amount : null,
            'emergency_allowance_balance' => $amount,
        ])->save();

        return response()->json([
            'success' => true,
            'data'    => [
                'uuid'                        => $account->uuid,
                'emergency_allowance_amount'  => $account->emergency_allowance_amount,
                'emergency_allowance_balance' => $account->emergency_allowance_balance,
            ],
        ]);
    }

    /**
     * Determine permission level based on age.
     * 6–7 → 1
     * 8–9 → 2
     * 10–11 → 3
     * 12–13 → 4
     * 14–15 → 5
     * 16–17 → 6.
     */
    private function getPermissionLevel(int $age): int
    {
        return match (true) {
            $age <= config('minor_family.permission_level_age_1_max', 7)  => 1,
            $age <= config('minor_family.permission_level_age_2_max', 9)  => 2,
            $age <= config('minor_family.permission_level_age_3_max', 11) => 3,
            $age <= config('minor_family.permission_level_age_4_max', 13) => 4,
            $age <= config('minor_family.permission_level_age_5_max', 15) => 5,
            default => config('minor_family.permission_level_default', 6),
        };
    }
}
