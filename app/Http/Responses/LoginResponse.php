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

        if ($this->isAdminPanelUser($request)) {
            return redirect('/admin');
        }

        return redirect()->intended((string) config('fortify.home', '/dashboard'));
    }

    private function isAdminPanelUser($request): bool
    {
        $user = $request->user();

        return $user !== null
            && method_exists($user, 'hasAnyRole')
            && $user->hasAnyRole(UserRoles::adminPanelRoles());
    }
}
