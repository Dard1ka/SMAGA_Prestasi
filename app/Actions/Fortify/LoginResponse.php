<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Actions\Accounts\DetermineActivationStep;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * After a successful password login: unfinished activation → `aktivasi`, otherwise the intended URL or `home`.
 */
final class LoginResponse implements LoginResponseContract
{
    public function __construct(private readonly DetermineActivationStep $activationStep) {}

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        if ($request->wantsJson()) {
            return response()->json(['two_factor' => false]);
        }

        $user = $request->user();

        if ($user !== null && ($this->activationStep)($user) !== null) {
            return redirect()->route('aktivasi');
        }

        return redirect()->intended(route('home'));
    }
}
