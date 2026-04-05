<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pay;

use App\Http\Controllers\Controller;
use App\Models\MoneyRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PayFallbackController extends Controller
{
    public function staticLink(string $username): View
    {
        $user = User::query()->where('username', $username)->first();

        return view('pay.fallback', [
            'display_name' => $user?->name ?? $username,
            'avatar_url'   => $user?->profile_photo_url ?? null,
            'amount'       => null,
            'note'         => null,
            'deep_link'    => "https://pay.maphapay.com/u/{$username}",
            'found'        => $user !== null,
        ]);
    }

    public function dynamicLink(Request $request, string $token): View
    {
        $mr = MoneyRequest::query()
            ->where('payment_token', $token)
            ->where('expires_at', '>', now())
            ->whereNull('paid_at')
            ->first();

        if (! $mr) {
            return view('pay.fallback', [
                'display_name' => null,
                'avatar_url'   => null,
                'amount'       => null,
                'note'         => null,
                'deep_link'    => null,
                'found'        => false,
            ]);
        }

        /** @var User|null $requester */
        $requester = User::query()->find($mr->requester_user_id);

        return view('pay.fallback', [
            'display_name' => $requester?->name,
            'avatar_url'   => $requester?->profile_photo_url ?? null,
            'amount'       => $mr->amount,
            'note'         => $mr->note,
            'deep_link'    => "https://pay.maphapay.com/r/{$token}",
            'found'        => true,
        ]);
    }
}
