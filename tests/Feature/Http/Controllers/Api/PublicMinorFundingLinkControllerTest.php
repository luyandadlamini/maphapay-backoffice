<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\MinorFamilyFundingAttempt;
use App\Domain\Account\Models\MinorFamilyFundingLink;
use App\Domain\Account\Services\MinorNotificationService;
use App\Domain\MtnMomo\Services\MtnMomoFamilyFundingAdapter;
use App\Http\Middleware\ResolveAccountContext;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicMinorFundingLinkControllerTest extends TestCase
{
    private string $tenantId;

    private User $creator;

    private Account $minorAccount;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    protected function shouldCreateDefaultAccountsInSetup(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ResolveAccountContext::class);
        $this->ensurePhase9Schema();

        DB::table('minor_family_funding_attempts')->delete();
        DB::table('minor_family_funding_links')->delete();
        DB::table('mtn_momo_transactions')->delete();

        $this->tenantId = (string) Str::uuid();
        DB::connection('central')->table('tenants')->insert([
            'id'            => $this->tenantId,
            'name'          => 'Public Minor Funding Link Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->creator = User::factory()->create([
            'name' => 'Grace Guardian',
        ]);
        $minorOwner = User::factory()->create([
            'name' => 'Sipho Makhanya',
        ]);

        $creatorAccount = Account::factory()->create([
            'user_uuid' => $this->creator->uuid,
            'type'      => 'personal',
        ]);

        $this->minorAccount = Account::factory()->create([
            'user_uuid'         => $minorOwner->uuid,
            'type'              => 'minor',
            'parent_account_id' => $creatorAccount->uuid,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function public_lookup_returns_active_funding_metadata(): void
    {
        $link = $this->createFundingLink([
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '1000.00',
            'collected_amount' => '250.00',
            'token'            => 'active-token',
            'title'            => "Support Sipho's school trip",
        ]);

        $this->getJson("/api/minor-support-links/{$link->token}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_link_uuid', $link->id)
            ->assertJsonPath('data.title', "Support Sipho's school trip")
            ->assertJsonPath('data.amount_mode', MinorFamilyFundingLink::AMOUNT_MODE_CAPPED)
            ->assertJsonPath('data.remaining_amount', '750.00')
            ->assertJsonPath('data.asset_code', 'SZL')
            ->assertJsonPath('data.provider_options.0', 'mtn_momo');
    }

    #[Test]
    public function expired_or_inactive_token_returns_terminal_response(): void
    {
        $expired = $this->createFundingLink([
            'token'      => 'expired-token',
            'status'     => MinorFamilyFundingLink::STATUS_EXPIRED,
            'expires_at' => now()->subMinute(),
        ]);

        $this->getJson("/api/minor-support-links/{$expired->token}")
            ->assertStatus(410);
    }

    #[Test]
    public function public_mtn_request_to_pay_obeys_bounds_and_returns_accepted_envelope(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'request-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '500.00',
            'collected_amount' => '100.00',
            'provider_options' => ['mtn_momo'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-23 13:20:00'));

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andReturn([
                'provider_name'         => 'mtn_momo',
                'provider_reference_id' => 'provider-attempt-accepted',
                'provider_status'       => 'pending',
            ]);
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();
        $this->app->instance(MinorNotificationService::class, $notifications);

        $response = $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Auntie Thandi',
            'sponsor_msisdn' => '+26876123456',
            'amount'         => '150.00',
            'asset_code'     => 'SZL',
        ])->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_link_uuid', $link->id)
            ->assertJsonPath('data.status', MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER)
            ->assertJsonPath('data.provider', 'mtn_momo')
            ->assertJsonPath('data.provider_reference_id', 'provider-attempt-accepted')
            ->assertJsonPath('data.amount', '150.00')
            ->assertJsonPath('data.asset_code', 'SZL');

        $this->assertDatabaseCount('minor_family_funding_attempts', 1);
        $this->assertDatabaseHas('minor_family_funding_attempts', [
            'id'                => $response->json('data.funding_attempt_uuid'),
            'funding_link_uuid' => $link->id,
            'status'            => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
        ]);
    }

    #[Test]
    public function duplicate_public_attempt_replays_without_creating_duplicate_attempts(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'dedupe-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '80.00',
            'provider_options' => ['mtn_momo'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-23 14:40:00'));

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andReturn([
                'provider_name'         => 'mtn_momo',
                'provider_reference_id' => 'provider-attempt-dedupe',
                'provider_status'       => 'pending',
            ]);
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();
        $this->app->instance(MinorNotificationService::class, $notifications);

        $payload = [
            'sponsor_name'   => 'Replay Sponsor',
            'sponsor_msisdn' => '+26876111111',
            'amount'         => '80.00',
            'asset_code'     => 'SZL',
        ];

        $first = $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", $payload)
            ->assertStatus(202);

        $second = $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", $payload)
            ->assertStatus(202);

        $this->assertSame(
            $first->json('data.funding_attempt_uuid'),
            $second->json('data.funding_attempt_uuid')
        );
        $this->assertDatabaseCount('minor_family_funding_attempts', 1);
    }

    #[Test]
    public function request_to_pay_rejects_when_sponsor_attempt_window_limit_is_exceeded(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'sponsor-window-limit-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '1000.00',
            'collected_amount' => '0.00',
            'provider_options' => ['mtn_momo'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-23 15:10:00'));

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->times(5)
            ->andReturnUsing(function (): array {
                static $callCount = 0;
                $callCount++;

                return [
                    'provider_name'         => 'mtn_momo',
                    'provider_reference_id' => sprintf('provider-attempt-limit-%d', $callCount),
                    'provider_status'       => 'pending',
                ];
            });
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->times(5);
        $this->app->instance(MinorNotificationService::class, $notifications);

        $basePayload = [
            'sponsor_name'   => 'Windowed Sponsor',
            'sponsor_msisdn' => '+26876122222',
            'asset_code'     => 'SZL',
        ];

        foreach ([10, 11, 12, 13, 14] as $amount) {
            $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", array_merge($basePayload, [
                'amount' => number_format((float) $amount, 2, '.', ''),
            ]))->assertStatus(202);
        }

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", array_merge($basePayload, [
            'amount' => '15.00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);

        $this->assertDatabaseCount('minor_family_funding_attempts', 5);
    }

    #[Test]
    public function sponsor_attempt_limit_respects_config_overrides(): void
    {
        Config::set('minor_family.public_funding.attempt_window_minutes', 60);
        Config::set('minor_family.public_funding.sponsor_max_attempts_per_window', 2);

        $link = $this->createFundingLink([
            'token'            => 'sponsor-config-limit-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '1000.00',
            'collected_amount' => '0.00',
            'provider_options' => ['mtn_momo'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-23 16:00:00'));

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->times(2)
            ->andReturnUsing(function (): array {
                static $callCount = 0;
                $callCount++;

                return [
                    'provider_name'         => 'mtn_momo',
                    'provider_reference_id' => sprintf('provider-config-limit-%d', $callCount),
                    'provider_status'       => 'pending',
                ];
            });
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->times(2);
        $this->app->instance(MinorNotificationService::class, $notifications);

        $basePayload = [
            'sponsor_name'   => 'Config Sponsor',
            'sponsor_msisdn' => '+26876144444',
            'asset_code'     => 'SZL',
        ];

        foreach ([20.0, 21.0] as $amount) {
            $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", array_merge($basePayload, [
                'amount' => number_format((float) $amount, 2, '.', ''),
            ]))->assertStatus(202);
        }

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", array_merge($basePayload, [
            'amount' => '22.00',
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);

        $this->assertDatabaseCount('minor_family_funding_attempts', 2);
    }

    #[Test]
    public function replay_of_existing_dedupe_attempt_still_succeeds_after_window_limit_is_hit(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'dedupe-after-limit-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '1000.00',
            'collected_amount' => '0.00',
            'provider_options' => ['mtn_momo'],
        ]);

        Carbon::setTestNow(Carbon::parse('2026-04-23 15:40:00'));

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->times(5)
            ->andReturnUsing(function (): array {
                static $callCount = 0;
                $callCount++;

                return [
                    'provider_name'         => 'mtn_momo',
                    'provider_reference_id' => sprintf('provider-attempt-dedupe-limit-%d', $callCount),
                    'provider_status'       => 'pending',
                ];
            });
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->times(5);
        $this->app->instance(MinorNotificationService::class, $notifications);

        $firstPayload = [
            'sponsor_name'   => 'Replay Sponsor',
            'sponsor_msisdn' => '+26876133333',
            'amount'         => '10.00',
            'asset_code'     => 'SZL',
        ];

        $first = $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", $firstPayload)
            ->assertStatus(202);

        foreach ([11, 12, 13, 14] as $amount) {
            $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
                'sponsor_name'   => 'Replay Sponsor',
                'sponsor_msisdn' => '+26876133333',
                'amount'         => number_format((float) $amount, 2, '.', ''),
                'asset_code'     => 'SZL',
            ])->assertStatus(202);
        }

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Replay Sponsor',
            'sponsor_msisdn' => '+26876133333',
            'amount'         => '15.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);

        $replayed = $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", $firstPayload)
            ->assertStatus(202);

        $this->assertSame(
            $first->json('data.funding_attempt_uuid'),
            $replayed->json('data.funding_attempt_uuid')
        );
        $this->assertDatabaseCount('minor_family_funding_attempts', 5);
    }

    #[Test]
    public function request_to_pay_rejects_asset_code_mismatch(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'asset-mismatch-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '80.00',
            'asset_code'       => 'SZL',
            'provider_options' => ['mtn_momo'],
        ]);

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Mismatch Sponsor',
            'sponsor_msisdn' => '+26876155555',
            'amount'         => '80.00',
            'asset_code'     => 'USD',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset_code']);

        $this->assertDatabaseCount('minor_family_funding_attempts', 0);
    }

    #[Test]
    public function request_to_pay_rejects_over_cap_amount(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'cap-rejection-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_CAPPED,
            'target_amount'    => '100.00',
            'collected_amount' => '90.00',
            'asset_code'       => 'SZL',
            'provider_options' => ['mtn_momo'],
        ]);

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Over Cap Sponsor',
            'sponsor_msisdn' => '+26876166666',
            'amount'         => '20.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);

        $this->assertDatabaseCount('minor_family_funding_attempts', 0);
    }

    #[Test]
    public function request_to_pay_rejects_amount_for_fixed_link(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'fixed-rejection-token',
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '50.00',
            'asset_code'       => 'SZL',
            'provider_options' => ['mtn_momo'],
        ]);

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Wrong Fixed Sponsor',
            'sponsor_msisdn' => '+26876177777',
            'amount'         => '40.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);

        $this->assertDatabaseCount('minor_family_funding_attempts', 0);
    }

    #[Test]
    public function request_to_pay_does_not_map_to_terminal_from_validation_message_text(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'message-text-guard-token',
            'status'           => MinorFamilyFundingLink::STATUS_ACTIVE,
            'expires_at'       => now()->addHour(),
            'provider_options' => ['mtn_momo'],
        ]);

        $integration = Mockery::mock(\App\Domain\Account\Services\MinorFamilyIntegrationService::class);
        $integration->shouldReceive('createPublicFundingAttempt')
            ->once()
            ->andThrow(ValidationException::withMessages([
                'minor_family_integration' => ['Funding link has expired.'],
            ]));
        $this->app->instance(\App\Domain\Account\Services\MinorFamilyIntegrationService::class, $integration);

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Message Guard Sponsor',
            'sponsor_msisdn' => '+26876199999',
            'amount'         => '100.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['minor_family_integration']);
    }

    #[Test]
    public function terminal_request_path_returns_terminal_response_without_message_parsing(): void
    {
        $link = $this->createFundingLink([
            'token'            => 'terminal-refresh-token',
            'status'           => MinorFamilyFundingLink::STATUS_ACTIVE,
            'expires_at'       => now()->addMinute(),
            'provider_options' => ['mtn_momo'],
        ]);

        $integration = Mockery::mock(\App\Domain\Account\Services\MinorFamilyIntegrationService::class);
        $integration->shouldReceive('createPublicFundingAttempt')
            ->once()
            ->andReturnUsing(function (MinorFamilyFundingLink $activeLink): void {
                MinorFamilyFundingLink::query()
                    ->whereKey($activeLink->id)
                    ->update([
                        'status'     => MinorFamilyFundingLink::STATUS_EXPIRED,
                        'expires_at' => now()->subMinute(),
                    ]);

                throw ValidationException::withMessages([
                    'minor_family_integration' => ['Policy denied.'],
                ]);
            });
        $this->app->instance(\App\Domain\Account\Services\MinorFamilyIntegrationService::class, $integration);

        $this->postJson("/api/minor-support-links/{$link->token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Terminal Sponsor',
            'sponsor_msisdn' => '+26876188888',
            'amount'         => '100.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(410)
            ->assertJsonPath('error_code', 'funding_link_expired');
    }

    #[Test]
    public function public_attempt_status_returns_sanitized_payload(): void
    {
        $link = $this->createFundingLink(['token' => 'status-token']);

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'tenant_id'             => $this->tenantId,
            'funding_link_uuid'     => $link->id,
            'minor_account_uuid'    => $link->minor_account_uuid,
            'status'                => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'sponsor_name'          => 'Auntie Secret',
            'sponsor_msisdn'        => '+26876999888',
            'amount'                => '120.00',
            'asset_code'            => 'SZL',
            'provider_name'         => 'mtn_momo',
            'provider_reference_id' => 'provider-attempt-private',
            'dedupe_hash'           => hash('sha256', (string) Str::uuid()),
            'wallet_credited_at'    => null,
        ]);

        $this->getJson("/api/minor-support-links/{$link->token}/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_attempt_uuid', $attempt->id)
            ->assertJsonPath('data.status', MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER)
            ->assertJsonPath('data.provider', 'mtn_momo')
            ->assertJsonPath('data.amount', '120.00')
            ->assertJsonPath('data.asset_code', 'SZL')
            ->assertJsonMissingPath('data.sponsor_name')
            ->assertJsonMissingPath('data.sponsor_msisdn')
            ->assertJsonMissingPath('data.provider_reference_id');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createFundingLink(array $overrides = []): MinorFamilyFundingLink
    {
        $defaults = [
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $this->minorAccount->uuid,
            'created_by_user_uuid'    => $this->creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $this->creator->uuid,
                'type'      => 'personal',
            ])->uuid,
            'title'            => 'Support Sipho',
            'note'             => 'Family support',
            'token'            => (string) Str::uuid(),
            'status'           => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '100.00',
            'target_amount'    => null,
            'collected_amount' => '0.00',
            'asset_code'       => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at'       => now()->addDay(),
        ];

        return MinorFamilyFundingLink::query()->create(array_merge($defaults, $overrides));
    }

    private function ensurePhase9Schema(): void
    {
        if (! Schema::hasTable('minor_family_funding_links')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100000_create_minor_family_funding_links_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('minor_family_funding_attempts')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100100_create_minor_family_funding_attempts_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasColumns('mtn_momo_transactions', ['context_type', 'context_uuid'])) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_04_23_100300_add_minor_family_context_to_mtn_momo_transactions_table.php',
                '--force' => true,
            ]);
        }
    }
}
