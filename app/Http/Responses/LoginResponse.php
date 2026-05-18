<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Domain\User\Values\UserRoles;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 204);
        }

        return redirect()->intended($this->redirectPath($request));
    }

    private function redirectPath($request): string
    {
        $user = $request->user();

        if ($user !== null && method_exists($user, 'hasAnyRole') && $user->hasAnyRole(UserRoles::adminPanelRoles())) {
            return '/admin';
        }

        return (string) config('fortify.home', '/dashboard');
    }
}
