<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Segments\Jobs;

use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Segments\Models\SegmentMembership;
use App\Jobs\Pricing\RefreshSegmentMembershipsJob;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class RefreshSegmentMembershipsJobTest extends TestCase
{
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
    }

    /**
     * @param  array<string, mixed>|null  $rules
     */
    private function makeDynamicSegment(?array $rules = null): CustomerSegment
    {
        return CustomerSegment::create([
            'code'   => 'dyn-' . uniqid(),
            'name'   => 'Dynamic Segment ' . uniqid(),
            'source' => SegmentSource::Dynamic->value,
            'active' => true,
            'rules'  => $rules ?? ['all' => [['field' => 'user.kyc_tier', 'operator' => '>=', 'value' => 1]]],
        ]);
    }

    private function makeStaticSegment(): CustomerSegment
    {
        return CustomerSegment::create([
            'code'   => 'sta-' . uniqid(),
            'name'   => 'Static Segment ' . uniqid(),
            'source' => SegmentSource::Static->value,
            'active' => true,
            'rules'  => null,
        ]);
    }

    public function test_creates_membership_when_user_qualifies_and_none_exists(): void
    {
        // user1 has KYC submitted → qualifies for tier ≥ 1 rule
        $user1 = User::factory()->create(['kyc_submitted_at' => now()->subDay()]);
        $user2 = User::factory()->create(['kyc_submitted_at' => null, 'kyc_approved_at' => null]);

        $segment1 = $this->makeDynamicSegment();
        $segment2 = $this->makeDynamicSegment();

        Cache::forget("segment_evaluator.user.{$user1->id}");

        (new RefreshSegmentMembershipsJob($user1->id))->handle(app(\App\Domain\Segments\Services\SegmentEvaluator::class));

        // user1 memberships were created for both segments
        $this->assertDatabaseHas('segment_memberships', [
            'user_id'    => $user1->id,
            'segment_id' => $segment1->id,
        ]);
        $this->assertDatabaseHas('segment_memberships', [
            'user_id'    => $user1->id,
            'segment_id' => $segment2->id,
        ]);

        // user2 is untouched — the job was scoped to user1
        $this->assertDatabaseMissing('segment_memberships', ['user_id' => $user2->id]);
    }

    public function test_deletes_membership_when_user_no_longer_qualifies(): void
    {
        // user does NOT qualify (no KYC)
        $user = User::factory()->create(['kyc_submitted_at' => null, 'kyc_approved_at' => null]);

        $segment = $this->makeDynamicSegment();

        // Pre-seed a stale membership
        SegmentMembership::create([
            'user_id'         => $user->id,
            'segment_id'      => $segment->id,
            'joined_at'       => now()->subDays(7),
            'materialised_at' => now()->subDays(7),
        ]);

        $this->assertDatabaseHas('segment_memberships', [
            'user_id'    => $user->id,
            'segment_id' => $segment->id,
        ]);

        (new RefreshSegmentMembershipsJob($user->id))->handle(app(\App\Domain\Segments\Services\SegmentEvaluator::class));

        $this->assertDatabaseMissing('segment_memberships', [
            'user_id'    => $user->id,
            'segment_id' => $segment->id,
        ]);
    }

    public function test_static_segment_memberships_are_not_touched(): void
    {
        $user = User::factory()->create(['kyc_submitted_at' => null, 'kyc_approved_at' => null]);

        $staticSegment = $this->makeStaticSegment();

        // Manually seed a static membership
        SegmentMembership::create([
            'user_id'         => $user->id,
            'segment_id'      => $staticSegment->id,
            'joined_at'       => now()->subDays(3),
            'materialised_at' => now()->subDays(3),
        ]);

        (new RefreshSegmentMembershipsJob($user->id))->handle(app(\App\Domain\Segments\Services\SegmentEvaluator::class));

        // Static membership survives — job only processes dynamic/hybrid segments
        $this->assertDatabaseHas('segment_memberships', [
            'user_id'    => $user->id,
            'segment_id' => $staticSegment->id,
        ]);
    }

    public function test_cache_is_cleared_after_refresh(): void
    {
        $user = User::factory()->create(['kyc_submitted_at' => now()->subDay()]);

        $this->makeDynamicSegment();

        // Populate the cache to confirm it gets busted
        Cache::put("segment_evaluator.user.{$user->id}", [99], 300);

        (new RefreshSegmentMembershipsJob($user->id))->handle(app(\App\Domain\Segments\Services\SegmentEvaluator::class));

        $this->assertNull(Cache::get("segment_evaluator.user.{$user->id}"));
    }
}
