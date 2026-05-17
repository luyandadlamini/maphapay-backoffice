<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Segments\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Segments\Models\SegmentMembership;
use App\Domain\Segments\Services\SegmentEvaluator;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class SegmentEvaluatorTest extends TestCase
{
    private SegmentEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('customer_segments')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_05_16_000002_create_customer_segments_table.php',
                '--force' => true,
            ]);
        }

        if (! Schema::hasTable('segment_memberships')) {
            Artisan::call('migrate', [
                '--path'  => 'database/migrations/2026_05_16_000009_create_segment_memberships_table.php',
                '--force' => true,
            ]);
        }

        $this->evaluator = new SegmentEvaluator();
    }

    private function makeSegment(SegmentSource $source, ?array $rules = null): CustomerSegment
    {
        return CustomerSegment::create([
            'code'   => $source->value . '-' . uniqid(),
            'name'   => ucfirst($source->value) . ' Test Segment',
            'source' => $source->value,
            'active' => true,
            'rules'  => $rules,
        ]);
    }

    private function makeMembership(User $user, CustomerSegment $segment, bool $expired = false): SegmentMembership
    {
        return SegmentMembership::create([
            'user_id'         => $user->id,
            'segment_id'      => $segment->id,
            'joined_at'       => now()->subDays(1),
            'expires_at'      => $expired ? now()->subHour() : null,
            'materialised_at' => now()->subDays(1),
        ]);
    }

    // ── Static segment ─────────────────────────────────────────────────────────

    public function test_static_segment_member_returns_true(): void
    {
        $user = User::factory()->create();
        $segment = $this->makeSegment(SegmentSource::Static);
        $this->makeMembership($user, $segment);

        $this->assertTrue($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_static_segment_non_member_returns_false(): void
    {
        $user = User::factory()->create();
        $segment = $this->makeSegment(SegmentSource::Static);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_expired_membership_returns_false(): void
    {
        $user = User::factory()->create();
        $segment = $this->makeSegment(SegmentSource::Static);
        $this->makeMembership($user, $segment, expired: true);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    // ── Dynamic segment ────────────────────────────────────────────────────────

    public function test_dynamic_segment_passing_dsl_returns_true(): void
    {
        $user = User::factory()->create(['kyc_submitted_at' => now()->subDay()]);

        $segment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '>=', 'value' => 1]],
        ]);

        $this->assertTrue($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_dynamic_segment_failing_dsl_returns_false(): void
    {
        $user = User::factory()->create([
            'kyc_submitted_at' => null,
            'kyc_approved_at'  => null,
        ]);

        $segment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '>=', 'value' => 1]],
        ]);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_dsl_account_tier_passes_when_tier_meets_threshold(): void
    {
        $user = User::factory()->create();
        Account::create([
            'user_uuid' => $user->uuid,
            'name'      => 'Primary',
            'type'      => 'personal',
            'tier'      => '2',
        ]);

        $segment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'account.tier', 'operator' => '>=', 'value' => 2]],
        ]);

        $this->assertTrue($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_dsl_account_tier_fails_when_tier_below_threshold(): void
    {
        $user = User::factory()->create();
        Account::create([
            'user_uuid' => $user->uuid,
            'name'      => 'Primary',
            'type'      => 'personal',
            'tier'      => '1',
        ]);

        $segment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'account.tier', 'operator' => '>=', 'value' => 2]],
        ]);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    // ── Hybrid segment ─────────────────────────────────────────────────────────

    public function test_hybrid_segment_fails_without_membership_even_when_dsl_passes(): void
    {
        $user = User::factory()->create(['kyc_approved_at' => now()->subDay()]);

        $segment = $this->makeSegment(SegmentSource::Hybrid, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '=', 'value' => 2]],
        ]);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_hybrid_segment_passes_when_both_membership_and_dsl_pass(): void
    {
        $user = User::factory()->create(['kyc_approved_at' => now()->subDay()]);

        $segment = $this->makeSegment(SegmentSource::Hybrid, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '=', 'value' => 2]],
        ]);

        $this->makeMembership($user, $segment);

        $this->assertTrue($this->evaluator->evaluate($user->id, $segment));
    }

    public function test_hybrid_segment_fails_when_membership_exists_but_dsl_fails(): void
    {
        $user = User::factory()->create([
            'kyc_submitted_at' => null,
            'kyc_approved_at'  => null,
        ]);

        $segment = $this->makeSegment(SegmentSource::Hybrid, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '=', 'value' => 2]],
        ]);

        $this->makeMembership($user, $segment);

        $this->assertFalse($this->evaluator->evaluate($user->id, $segment));
    }

    // ── userSegmentIds ──────────────────────────────────────────────────────────

    public function test_user_segment_ids_returns_all_matching_segment_ids(): void
    {
        $user = User::factory()->create(['kyc_submitted_at' => now()->subDay()]);

        $staticSegment = $this->makeSegment(SegmentSource::Static);
        $this->makeMembership($user, $staticSegment);

        $dynamicSegment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '>=', 'value' => 1]],
        ]);

        Cache::forget("segment_evaluator.user.{$user->id}");

        $ids = $this->evaluator->userSegmentIds($user->id);

        $this->assertContains($staticSegment->id, $ids);
        $this->assertContains($dynamicSegment->id, $ids);
    }

    public function test_user_segment_ids_excludes_non_matching_segments(): void
    {
        $user = User::factory()->create([
            'kyc_submitted_at' => null,
            'kyc_approved_at'  => null,
        ]);

        $dynamicSegment = $this->makeSegment(SegmentSource::Dynamic, [
            'all' => [['field' => 'user.kyc_tier', 'operator' => '>=', 'value' => 1]],
        ]);

        Cache::forget("segment_evaluator.user.{$user->id}");

        $ids = $this->evaluator->userSegmentIds($user->id);

        $this->assertNotContains($dynamicSegment->id, $ids);
    }

    public function test_user_segment_ids_result_is_cached(): void
    {
        $user = User::factory()->create();
        $segment = $this->makeSegment(SegmentSource::Static);
        $this->makeMembership($user, $segment);

        Cache::forget("segment_evaluator.user.{$user->id}");

        $first = $this->evaluator->userSegmentIds($user->id);
        $second = $this->evaluator->userSegmentIds($user->id);

        $this->assertSame($first, $second);
        $this->assertNotNull(Cache::get("segment_evaluator.user.{$user->id}"));
    }
}
