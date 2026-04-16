<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Services\AccountMembershipService;
use App\Http\Controllers\Controller;
use App\Policies\AccountPolicy;
use App\Rules\NoControlCharacters;
use App\Rules\NoSqlInjection;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            'name' => ['required', 'string', 'max:255', new NoControlCharacters(), new NoSqlInjection()],
            'date_of_birth' => ['required', 'date_format:Y-m-d', 'before:today'],
            'photo_id_path' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var \App\Models\User $user */
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
        $dateOfBirth = Carbon::createFromFormat('Y-m-d', $validated['date_of_birth']);
        $age = (int) floor($dateOfBirth->diffInYears(now(), true));

        if ($age < 6 || $age > 17) {
            return response()->json([
                'success' => false,
                'errors' => [
                    'date_of_birth' => ['Child must be between 6 and 17 years old.'],
                ],
            ], 422);
        }

        // Determine tier: grow (6-12) or rise (13-17)
        $tier = $age < 13 ? 'grow' : 'rise';

        // Determine permission level based on age
        $permissionLevel = $this->getPermissionLevel($age);

        try {
            $sanitizedName = strip_tags($validated['name']);
            $sanitizedName = htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8');
            $sanitizedName = (string) preg_replace('/javascript:/i', '', $sanitizedName);
            $sanitizedName = (string) preg_replace('/data:/i', '', $sanitizedName);
            $sanitizedName = (string) preg_replace('/vbscript:/i', '', $sanitizedName);
            $sanitizedName = trim($sanitizedName);

            $account = Account::create([
                'user_uuid' => $user->uuid,
                'parent_account_id' => $parentMembership->account_uuid,
                'name' => $sanitizedName,
                'account_type' => 'minor',
                'account_tier' => $tier,
                'permission_level' => $permissionLevel,
            ]);

            $membership = $this->membershipService->createGuardianMembership($user, $tenantId, $account);

            return response()->json([
                'success' => true,
                'data' => [
                    'account' => [
                        'uuid' => $account->uuid,
                        'account_type' => $account->account_type,
                        'name' => $account->name,
                        'account_tier' => $account->account_tier,
                        'permission_level' => $account->permission_level,
                        'parent_account_id' => $account->parent_account_id,
                    ],
                    'membership' => [
                        'account_uuid' => $membership->account_uuid,
                        'user_uuid' => $membership->user_uuid,
                        'role' => $membership->role,
                        'status' => $membership->status,
                        'account_type' => $membership->account_type,
                    ],
                ],
            ], 201);
        } catch (Throwable $e) {
            Log::error('MinorAccountController: store failed', [
                'user_uuid' => $user->uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create minor account. Please try again.',
            ], 500);
        }
    }

    /**
     * Determine permission level based on age.
     * 6–7 → 1
     * 8–9 → 2
     * 10–11 → 3
     * 12–13 → 4
     * 14–15 → 5
     * 16–17 → 6
     */
    private function getPermissionLevel(int $age): int
    {
        return match (true) {
            $age <= 7 => 1,
            $age <= 9 => 2,
            $age <= 11 => 3,
            $age <= 13 => 4,
            $age <= 15 => 5,
            default => 6,
        };
    }
}
