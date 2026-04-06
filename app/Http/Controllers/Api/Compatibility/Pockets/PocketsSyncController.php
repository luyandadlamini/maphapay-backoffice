<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Pockets;

use App\Domain\Mobile\Models\Pocket;
use App\Http\Controllers\Api\Compatibility\Concerns\ParsesChangedSince;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PocketsSyncController extends Controller
{
    use ParsesChangedSince;

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changedSince = $this->parseChangedSince($request);

        $query = Pocket::with('smartRule')
            ->where('user_uuid', $user->uuid)
            ->orderBy('updated_at');

        if ($changedSince !== null) {
            $query->where(function ($builder) use ($changedSince): void {
                $builder->where('updated_at', '>', $changedSince)
                    ->orWhereHas('smartRule', function ($smartRuleQuery) use ($changedSince): void {
                        // @phpstan-ignore argument.type
                    $smartRuleQuery->where('updated_at', '>', $changedSince);
                    });
            });
        }

        $pockets = $query->get();

        $latestPocket = Pocket::where('user_uuid', $user->uuid)->max('updated_at');
        $latestRule = Pocket::query()
            ->where('user_uuid', $user->uuid)
            ->join('pocket_smart_rules', 'pocket_smart_rules.pocket_id', '=', 'pockets.uuid')
            ->max('pocket_smart_rules.updated_at');

        return response()->json([
            'status'          => 'success',
            'remark'          => 'pockets_sync',
            'items'           => $pockets->map(fn (Pocket $pocket): array => $this->formatPocket($pocket))->values()->all(),
            'deleted_ids'     => [],
            'next_sync_token' => $this->nextSyncToken([$latestPocket, $latestRule]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formatPocket(Pocket $pocket): array
    {
        $smartRule = $pocket->smartRule;

        return [
            'id'             => (string) $pocket->uuid,
            'user_id'        => $pocket->user_uuid,
            'name'           => $pocket->name,
            'target_amount'  => number_format((float) $pocket->target_amount, 2, '.', ''),
            'current_amount' => number_format((float) $pocket->current_amount, 2, '.', ''),
            'target_date'    => $pocket->target_date?->format('Y-m-d') ?? '2027-12-31',
            'category'       => $pocket->category,
            'color'          => $pocket->color,
            'is_completed'   => $pocket->is_completed,
            'smart_rules'    => $smartRule ? [
                'id'                 => (string) $smartRule->id,
                'pocket_id'          => (string) $pocket->uuid,
                'round_up_change'    => $smartRule->round_up_change,
                'auto_save_deposits' => $smartRule->auto_save_deposits,
                'auto_save_salary'   => $smartRule->auto_save_salary,
                'lock_pocket'        => $smartRule->lock_pocket,
            ] : [
                'round_up_change'    => false,
                'auto_save_deposits' => false,
                'auto_save_salary'   => false,
                'lock_pocket'        => false,
            ],
            'updated_at' => $pocket->updated_at?->toIso8601String(),
        ];
    }
}
