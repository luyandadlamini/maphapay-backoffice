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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PublicMinorFundingLinkTokenHashTest extends TestCase
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
            'name'          => 'Token Hash Test Tenant',
            'plan'          => 'default',
            'team_id'       => null,
            'trial_ends_at' => null,
            'created_at'    => now(),
            'updated_at'    => now(),
            'data'          => json_encode([]),
        ]);

        $this->creator = User::factory()->create([
            'name' => 'Hash Guardian',
        ]);
        $minorOwner = User::factory()->create([
            'name' => 'Hash Minor',
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
    public function plaintext_token_is_64_characters_after_creation(): void
    {
        $link = $this->createFundingLink();

        $this->assertNotNull($link->plaintext_token);
        $this->assertSame(64, strlen($link->plaintext_token));
    }

    #[Test]
    public function token_column_stores_sha256_hash_not_plaintext(): void
    {
        $link = $this->createFundingLink();
        $plaintextToken = $link->plaintext_token;

        $dbToken = DB::table('minor_family_funding_links')
            ->where('id', $link->id)
            ->value('token');

        $this->assertSame(hash('sha256', $plaintextToken), $dbToken);
        $this->assertNotSame($plaintextToken, $dbToken);
    }

    #[Test]
    public function show_endpoint_resolves_link_via_hashed_token(): void
    {
        $link = $this->createFundingLink();

        $this->getJson("/api/minor-support-links/{$link->plaintext_token}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_link_uuid', $link->id);
    }

    #[Test]
    public function show_endpoint_rejects_short_invalid_token(): void
    {
        $this->getJson('/api/minor-support-links/short-token')
            ->assertStatus(404);
    }

    #[Test]
    public function show_endpoint_rejects_uuid_length_token(): void
    {
        $uuidToken = (string) Str::uuid();

        $this->getJson("/api/minor-support-links/{$uuidToken}")
            ->assertStatus(404);
    }

    #[Test]
    public function show_endpoint_rejects_random_32_char_token(): void
    {
        $shortToken = Str::random(32);

        $this->getJson("/api/minor-support-links/{$shortToken}")
            ->assertStatus(404);
    }

    #[Test]
    public function request_to_pay_resolves_link_via_hashed_token(): void
    {
        $link = $this->createFundingLink([
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '100.00',
            'provider_options' => ['mtn_momo'],
        ]);

        $adapter = Mockery::mock(MtnMomoFamilyFundingAdapter::class);
        $adapter->shouldReceive('initiateInboundCollection')
            ->once()
            ->andReturn([
                'provider_name'         => 'mtn_momo',
                'provider_reference_id' => 'provider-hash-test',
                'provider_status'       => 'pending',
            ]);
        $this->app->instance(MtnMomoFamilyFundingAdapter::class, $adapter);

        $notifications = Mockery::mock(MinorNotificationService::class);
        $notifications->shouldReceive('notify')->once();
        $this->app->instance(MinorNotificationService::class, $notifications);

        Carbon::setTestNow(Carbon::parse('2026-04-26 10:00:00'));

        $this->postJson("/api/minor-support-links/{$link->plaintext_token}/mtn/request-to-pay", [
            'sponsor_name'   => 'Hash Sponsor',
            'sponsor_msisdn' => '+26876123456',
            'amount'         => '100.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_link_uuid', $link->id);
    }

    #[Test]
    public function request_to_pay_rejects_short_token(): void
    {
        $this->postJson('/api/minor-support-links/short/mtn/request-to-pay', [
            'sponsor_name'   => 'Short Sponsor',
            'sponsor_msisdn' => '+26876123456',
            'amount'         => '50.00',
            'asset_code'     => 'SZL',
        ])
            ->assertStatus(404);
    }

    #[Test]
    public function attempt_status_resolves_link_via_hashed_token(): void
    {
        $link = $this->createFundingLink([
            'amount_mode'  => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount' => '100.00',
        ]);

        $attempt = MinorFamilyFundingAttempt::query()->create([
            'tenant_id'             => $this->tenantId,
            'funding_link_uuid'     => $link->id,
            'minor_account_uuid'    => $link->minor_account_uuid,
            'status'                => MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER,
            'sponsor_name'          => 'Hash Attempt Sponsor',
            'sponsor_msisdn'        => '+26876999888',
            'amount'                => '100.00',
            'asset_code'            => 'SZL',
            'provider_name'         => 'mtn_momo',
            'provider_reference_id' => 'provider-hash-attempt',
            'dedupe_hash'           => hash('sha256', (string) Str::uuid()),
        ]);

        $this->getJson("/api/minor-support-links/{$link->plaintext_token}/attempts/{$attempt->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.funding_attempt_uuid', $attempt->id)
            ->assertJsonPath('data.status', MinorFamilyFundingAttempt::STATUS_PENDING_PROVIDER);
    }

    #[Test]
    public function attempt_status_rejects_short_token(): void
    {
        $fakeAttemptUuid = (string) Str::uuid();

        $this->getJson("/api/minor-support-links/short/attempts/{$fakeAttemptUuid}")
            ->assertStatus(404);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createFundingLink(array $overrides = []): MinorFamilyFundingLink
    {
        $plaintextToken = Str::random(64);

        $defaults = [
            'tenant_id'               => $this->tenantId,
            'minor_account_uuid'      => $this->minorAccount->uuid,
            'created_by_user_uuid'    => $this->creator->uuid,
            'created_by_account_uuid' => Account::factory()->create([
                'user_uuid' => $this->creator->uuid,
                'type'      => 'personal',
            ])->uuid,
            'title'            => 'Token Hash Test Link',
            'note'             => 'Testing token hashing',
            'token'            => hash('sha256', $plaintextToken),
            'status'           => MinorFamilyFundingLink::STATUS_ACTIVE,
            'amount_mode'      => MinorFamilyFundingLink::AMOUNT_MODE_FIXED,
            'fixed_amount'     => '100.00',
            'target_amount'    => null,
            'collected_amount' => '0.00',
            'asset_code'       => 'SZL',
            'provider_options' => ['mtn_momo'],
            'expires_at'       => now()->addDay(),
        ];

        $link = MinorFamilyFundingLink::query()->create(array_merge($defaults, $overrides));
        $link->plaintext_token = $plaintextToken;

        return $link;
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
