<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\Users;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/user/exist.
 *
 * Mobile-compat recipient lookup. Accepts a single `user` field that may be
 * a username, mobile (E.164, national, or raw digits), or numeric user ID.
 * Returns the canonical compat envelope:
 *   { status: 'success'|'error', message: string[], data: { id, username, display_name, mobile } | null }
 *
 * Backend is SOT for field names — see CLAUDE.md compat contract.
 */
final class UserExistController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user' => ['required', 'string', 'max:120'],
        ]);

        $caller = $request->user();
        abort_if($caller === null, 401);

        $query = trim($data['user']);
        $digits = preg_replace('/\D+/', '', $query) ?? '';

        /** @var User|null $peer */
        $peer = User::query()
            ->whereKeyNot($caller->getKey())
            ->whereNull('frozen_at')
            ->where(function (Builder $q) use ($query, $digits): void {
                $q->where('username', $query);

                if ($digits !== '') {
                    $q->orWhere('mobile', $digits)
                        ->orWhere('mobile', '+' . $digits);
                }

                if (ctype_digit($query)) {
                    $q->orWhere('id', $query);
                }
            })
            ->first();

        if ($peer === null) {
            return response()->json([
                'status'  => 'error',
                'message' => ['Recipient not found.'],
                'data'    => null,
            ]);
        }

        return response()->json([
            'status'  => 'success',
            'message' => [],
            'data'    => [
                'id'           => $peer->id,
                'username'     => $peer->username,
                'display_name' => $peer->name ?? $peer->username,
                'mobile'       => $peer->mobile,
            ],
        ]);
    }
}
