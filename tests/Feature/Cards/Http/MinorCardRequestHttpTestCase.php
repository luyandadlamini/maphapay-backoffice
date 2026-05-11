<?php

declare(strict_types=1);

namespace Tests\Feature\Cards\Http;

use App\Domain\Account\Constants\MinorCardConstants;
use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountMembership;
use App\Domain\Account\Models\MinorCardRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase as BaseTestCase;

/**
 * account_memberships uses the central connection while user/account fixtures use
 * the default connection. Per-test transactions on default only cause InnoDB FK
 * lock waits when central validates membership rows against users.
 */
abstract class MinorCardRequestHttpTestCase extends BaseTestCase
{
    protected User $guardian;

    protected Account $minorAccount;

    protected string $tenantId;

    protected MinorCardRequest $minorRequest;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->guardian = $this->user;
        $this->guardian->update([
            'kyc_status'      => 'approved',
            'kyc_approved_at' => now(),
        ]);

        $this->app->instance(\App\Http\Middleware\CheckKycApproved::class, new class {
            public function handle($request, $next)
            {
                return $next($request);
            }
        });

        $child = User::factory()->create();
        $this->minorAccount = $this->createAccount($child);
        $this->minorAccount->update(['type' => 'minor', 'tier' => 'rise']);

        $this->tenantId = (string) Str::uuid();
        if (! DB::connection('central')->table('tenants')->where('id', $this->tenantId)->exists()) {
            DB::connection('central')->table('tenants')->insert([
                'id'            => $this->tenantId,
                'name'          => 'T',
                'plan'          => 'default',
                'team_id'       => null,
                'trial_ends_at' => null,
                'created_at'    => now(),
                'updated_at'    => now(),
                'data'          => json_encode([]),
            ]);
        }

        AccountMembership::query()->create([
            'id'             => (string) Str::uuid(),
            'user_uuid'      => $this->guardian->uuid,
            'tenant_id'      => $this->tenantId,
            'account_uuid'   => $this->minorAccount->uuid,
            'account_type'   => 'minor',
            'role'           => 'guardian',
            'status'         => 'active',
        ]);

        $this->app->instance(\App\Http\Middleware\ResolveAccountContext::class, new class($this->minorAccount) {
            public function __construct(private Account $acc) {}

            public function handle($request, $next)
            {
                $request->attributes->set('account_uuid', $this->acc->uuid);
                $request->attributes->set('account_type', 'minor');

                return $next($request);
            }
        });

        $this->minorRequest = MinorCardRequest::create([
            'minor_account_uuid'     => $this->minorAccount->uuid,
            'requested_by_user_uuid' => $this->guardian->uuid,
            'request_type'           => MinorCardConstants::REQUEST_TYPE_PARENT_INITIATED,
            'status'                 => MinorCardConstants::STATUS_PENDING_APPROVAL,
            'requested_network'      => 'visa',
        ]);
    }
}
