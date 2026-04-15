<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Account\Models\AccountMembership;
use App\Models\Tenant;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Stancl\Tenancy\Tenancy;
use Symfony\Component\HttpFoundation\Response;

class ResolveAccountContext
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $membership = $this->resolveMembership($request, (string) $user->uuid);

        if ($membership instanceof JsonResponse) {
            return $membership;
        }

        if ($membership !== null) {
            $this->applyMembershipContext($request, $membership);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($this->tenancy->initialized) {
            $this->tenancy->end();
        }
    }

    private function resolveMembership(Request $request, string $userUuid): AccountMembership|JsonResponse|null
    {
        $requestedAccountId = $request->header('X-Account-Id');

        if (is_string($requestedAccountId) && $requestedAccountId !== '') {
            $membership = AccountMembership::query()
                ->forUser($userUuid)
                ->forAccount($requestedAccountId)
                ->active()
                ->first();

            if ($membership === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have access to this account.',
                ], 403);
            }

            return $membership;
        }

        return AccountMembership::query()
            ->forUser($userUuid)
            ->active()
            ->orderByRaw("case when account_type = 'personal' then 0 else 1 end")
            ->orderByDesc('joined_at')
            ->first();
    }

    private function applyMembershipContext(Request $request, AccountMembership $membership): void
    {
        $tenant = Tenant::on('central')->find($membership->tenant_id);

        if ($tenant === null) {
            abort(503, 'Account context temporarily unavailable.');
        }

        $this->tenancy->initialize($tenant);

        $request->attributes->set('account_membership', $membership);
        $request->attributes->set('account_uuid', $membership->account_uuid);
        $request->attributes->set('account_type', $membership->account_type);
        $request->attributes->set('account_role', $membership->role);
        $request->attributes->set('tenant_id', $membership->tenant_id);
    }
}
