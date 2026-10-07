<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Accounts\RevokeUserSessions;
use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Alias `active`, also appended to the `web` group. A signed-in user whose account became disabled or
 * archived (D-29, D-30) is logged out on the next request and all of their sessions are revoked.
 */
class EnsureAccountActive
{
    public const MESSAGE = 'Sesi sudah berakhir. Silakan masuk lagi.';

    public function __construct(private readonly RevokeUserSessions $revokeSessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || self::canUseAccount($user)) {
            return $next($request);
        }

        $this->revokeSessions->handle($user);

        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson()) {
            abort(401, self::MESSAGE);
        }

        return redirect()->route('login')->withErrors(['identifier' => self::MESSAGE]);
    }

    public static function canUseAccount(object $user): bool
    {
        $status = $user->status ?? null;

        return $status instanceof UserStatus && $status->canLogIn();
    }
}
