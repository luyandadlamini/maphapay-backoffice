<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Compatibility\SocialMoney;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/social-money/user-lookup/{query}.
 *
 * Social add-friend lookup endpoint.
 * Accepts username, national mobile digits, or +268 prefixed mobile input.
 */
class SocialUserLookupController extends Controller
{
    public function __invoke(Request $request, string $query): JsonResponse
    {
        $query = trim($query);
        if ($query === '') {
            return response()->json([
                'status'  => 'error',
                'remark'  => 'social_user_lookup',
                'message' => ['Missing search query.'],
            ], 422);
        }

        $raw = preg_replace('/\s+/', '', $query) ?? $query;
        $digits = preg_replace('/\D+/', '', $query) ?? '';

        $national = $digits;
        $e164 = null;
        if (str_starts_with($digits, '268') && strlen($digits) > 8) {
            $national = substr($digits, 3);
            $e164 = '+268' . $national;
        } elseif (str_starts_with($raw, '+') && str_starts_with($digits, '268') && strlen($digits) > 8) {
            $national = substr($digits, 3);
            $e164 = '+268' . $national;
        }

        /** @var User $authUser */
        $authUser = $request->user();
        $peer = User::query()
            ->whereKeyNot($authUser->getKey())
            ->whereNull('frozen_at')
            ->where(function (Builder $q) use ($query, $raw, $digits, $national, $e164): void {
                $q->where('username', $query)
                    ->orWhere('username', $raw);

                if ($digits !== '') {
                    $q->orWhere('mobile', $digits)
                        ->orWhere('mobile', $national);
                }

                if ($e164 !== null) {
                    $q->orWhereRaw('CONCAT(dial_code, mobile) = ?', [$e164]);
                } elseif ($raw !== '') {
                    $q->orWhereRaw('CONCAT(dial_code, mobile) = ?', [$raw]);
                }
            })
            ->first();

        return response()->json([
            'status' => 'success',
            'remark' => 'social_user_lookup',
            'data'   => [
                'user' => $peer ? [
                    'id'        => $peer->id,
                    'username'  => $peer->username,
                    'firstname' => $peer->name ? explode(' ', trim($peer->name), 2)[0] : null,
                    'lastname'  => $peer->name && str_contains(trim($peer->name), ' ')
                        ? explode(' ', trim($peer->name), 2)[1]
                        : null,
                    'mobile' => $peer->mobile,
                    'email'  => $peer->email,
                ] : null,
            ],
        ]);
    }
}
