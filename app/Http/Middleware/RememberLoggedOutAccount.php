<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The next account must not land on the page of the account that just logged out (QA of 2026-10-06: after
 * "Keluar", Back asks the server for the old page; the guest is sent to the login and that address is kept as
 * the "intended" one, so the Admin who logged in next on the same computer landed on `/kesiswaan`).
 *
 * How: a logout ends the session, so nothing can be kept in it at that moment. The Logout listener notes the
 * account on the request, and this middleware (group `web`, so it runs inside StartSession and around every
 * place that logs somebody out) writes it into the new guest session. At the next login the Login listener
 * compares: a different account → the intended address is forgotten and the visitor goes to the beranda; the
 * same account → it is kept, so somebody who logs in again still gets back to where they were, and a link from
 * a mail still works in a browser nobody logged out of.
 *
 * Both listeners are registered in App\Providers\FortifyServiceProvider. The Login event covers the password
 * login and the 2FA challenge alike.
 */
final class RememberLoggedOutAccount
{
    /** Session key: id of the account that logged out in this browser, until the next login. */
    public const SESSION_KEY = 'sth.logged_out_user';

    private const REQUEST_ATTRIBUTE = 'sth.logged_out_user';

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $userId = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($userId !== null && $request->hasSession() && Auth::guest()) {
            $request->session()->put(self::SESSION_KEY, $userId);
        }

        return $response;
    }

    public static function loggedOut(Logout $event): void
    {
        if ($event->user !== null) {
            request()->attributes->set(self::REQUEST_ATTRIBUTE, $event->user->getAuthIdentifier());
        }
    }

    public static function loggedIn(Login $event): void
    {
        $request = request();

        if (! $request->hasSession()) {
            return;
        }

        $previous = $request->session()->pull(self::SESSION_KEY);

        if ($previous !== null && (string) $previous !== (string) $event->user->getAuthIdentifier()) {
            $request->session()->forget('url.intended');
        }
    }
}
