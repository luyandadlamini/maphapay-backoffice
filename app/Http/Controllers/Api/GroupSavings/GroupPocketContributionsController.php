<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\GroupSavings;

use App\Http\Controllers\Controller;
use App\Models\GroupPocket;
use App\Models\GroupPocketContribution;
use App\Models\ThreadParticipant;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GroupPocketContributionsController extends Controller
{
    public function index(Request $request, int $pocketId): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pocket = GroupPocket::findOrFail($pocketId);

        $isMember = ThreadParticipant::query()
            ->where('thread_id', $pocket->thread_id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            return response()->json(['status' => 'error', 'message' => ['Not a member of this group']], 403);
        }

        $contributions = GroupPocketContribution::query()
            ->where('group_pocket_id', $pocket->id)
            ->with('user:id,name')
            ->get()
            ->map(fn (GroupPocketContribution $c) => [
                'user_id'   => $c->user_id,
                'user_name' => $c->user !== null ? $c->user->name : 'Unknown',
                'amount'    => number_format((float) $c->amount, 2, '.', ''),
            ]);

        return response()->json([
            'status' => 'success',
            'data'   => ['contributions' => $contributions->values()],
        ]);
    }
}
