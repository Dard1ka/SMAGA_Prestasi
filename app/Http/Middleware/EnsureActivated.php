<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Accounts\DetermineActivationStep;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `activated`. Until the first-login activation is finished (new password, bound + verified email),
 * the only reachable screen is `aktivasi` (docs/SECURITY.md §3a K4).
 */
class EnsureActivated
{
    public function __construct(private readonly DetermineActivationStep $activationStep) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ($this->activationStep)($user) === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Selesaikan aktivasi akun dulu.');
        }

        return redirect()->route('aktivasi');
    }
}
