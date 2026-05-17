<?php

declare(strict_types=1);

namespace App\Jobs\Pricing;

use App\Domain\Segments\Enums\SegmentSource;
use App\Domain\Segments\Models\CustomerSegment;
use App\Domain\Segments\Models\SegmentMembership;
use App\Domain\Segments\Services\SegmentEvaluator;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RefreshSegmentMembershipsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(SegmentEvaluator $evaluator): void
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, CustomerSegment> $segments */
        $segments = CustomerSegment::active()
            ->whereIn('source', [SegmentSource::Dynamic->value, SegmentSource::Hybrid->value])
            ->get();

        if ($segments->isEmpty()) {
            return;
        }

        if ($this->userId !== null) {
            $this->refreshUser($this->userId, $segments, $evaluator);

            return;
        }

        User::query()
            ->orderBy('id')
            ->chunkById(200, function ($users) use ($segments, $evaluator): void {
                foreach ($users as $user) {
                    $this->refreshUser($user->id, $segments, $evaluator);
                }
            });
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, CustomerSegment>  $segments
     */
    private function refreshUser(int $userId, $segments, SegmentEvaluator $evaluator): void
    {
        foreach ($segments as $segment) {
            try {
                $qualifies = $evaluator->evaluate($userId, $segment);

                $existing = SegmentMembership::query()
                    ->where('user_id', $userId)
                    ->where('segment_id', $segment->id)
                    ->first();

                if ($qualifies && $existing === null) {
                    SegmentMembership::create([
                        'user_id'         => $userId,
                        'segment_id'      => $segment->id,
                        'joined_at'       => now(),
                        'materialised_at' => now(),
                    ]);
                } elseif (! $qualifies && $existing !== null) {
                    $existing->delete();
                }
            } catch (Throwable $e) {
                Log::warning('segment_refresh.evaluate_failed', [
                    'user_id'    => $userId,
                    'segment_id' => $segment->id,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        Cache::forget("segment_evaluator.user.{$userId}");
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        $tags = ['segments', 'pricing'];

        if ($this->userId !== null) {
            $tags[] = "user:{$this->userId}";
        }

        return $tags;
    }
}
