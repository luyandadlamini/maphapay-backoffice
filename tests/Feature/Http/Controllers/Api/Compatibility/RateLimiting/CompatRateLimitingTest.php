<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api\Compatibility\RateLimiting;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\ControllerTestCase;

/**
 * Phase 16 — per-user rate limiting on compat money-moving endpoints.
 *
 * The kyc_approved middleware runs before the throttle middleware, so tests
 * must create users with kyc_status = 'approved' to reach the rate limiter.
 */
class CompatRateLimitingTest extends ControllerTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Flush ALL cache so rate-limiter counters start at zero for each test.
        Cache::flush();

        $this->user = User::factory()->create(['kyc_status' => 'approved']);
    }

    // -------------------------------------------------------------------------
    // send-money/store  (10 req/min per user)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_send_money_tenth_request_is_not_throttled(): void
    {
        config(['maphapay_migration.enable_send_money' => true]);
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        // Fire 9 requests with an intentionally invalid body.
        // The rate limiter counts each request before validation runs.
        for ($i = 0; $i < 9; $i++) {
            $this->postJson('/api/send-money/store', []);
        }

        // 10th request: rate limiter has not tripped yet → 422 (validation), not 429.
        $this->postJson('/api/send-money/store', [])
            ->assertStatus(422);
    }

    #[Test]
    public function test_send_money_eleventh_request_returns_429_compat_envelope(): void
    {
        config(['maphapay_migration.enable_send_money' => true]);
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/send-money/store', []);
        }

        // 11th request: throttle fires before validation — must return 429.
        $this->postJson('/api/send-money/store', [])
            ->assertStatus(429)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Too many requests. Please try again later.',
            ]);
    }

    // -------------------------------------------------------------------------
    // mtn/disbursement  (5 req/min per user)
    // -------------------------------------------------------------------------

    #[Test]
    public function test_mtn_disbursement_fifth_request_is_not_throttled(): void
    {
        config(['maphapay_migration.enable_mtn_momo' => true]);
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/mtn/disbursement', []);
        }

        // 5th request: rate limiter has not tripped yet → 422 (validation), not 429.
        $this->postJson('/api/mtn/disbursement', [])
            ->assertStatus(422);
    }

    #[Test]
    public function test_mtn_disbursement_sixth_request_returns_429_compat_envelope(): void
    {
        config(['maphapay_migration.enable_mtn_momo' => true]);
        Sanctum::actingAs($this->user, ['read', 'write', 'delete']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mtn/disbursement', []);
        }

        // 6th request: throttle fires before validation — must return 429.
        $this->postJson('/api/mtn/disbursement', [])
            ->assertStatus(429)
            ->assertJson([
                'status'  => 'error',
                'message' => 'Too many requests. Please try again later.',
            ]);
    }
}
