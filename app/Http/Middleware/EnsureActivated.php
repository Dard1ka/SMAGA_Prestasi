<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\DetermineActivationStep;
use App\Actions\Accounts\RevokeUserSessions;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `activated`. Until the first-login activation is finished (new password, bound + verified email),
 * the only reachable screen is `aktivasi` (docs/SECURITY.md §3a K4).
 *
 * B1: a frozen account (K3 failed logins, K8 "Bukan saya?") loses its session here as well, in case a session
 * survived the revocation (non-database session drivers).
 */
class EnsureActivated
{
    public function __construct(
        private readonly DetermineActivationStep $activationStep,
        private readonly RevokeUserSessions $revokeSessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && AccountControls::isFrozen($user)) {
            return $this->logoutFrozen($request, $user);
        }

        if ($user === null || ($this->activationStep)($user) === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            abort(403, 'Selesaikan aktivasi akun dulu.');
        }

        return redirect()->route('aktivasi');
    }

    private function logoutFrozen(Request $request, User $user): Response
    {
        $this->revokeSessions->handle($user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            abort(401, EnsureAccountActive::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['identifier' => EnsureAccountActive::MESSAGE]);
    }
}
