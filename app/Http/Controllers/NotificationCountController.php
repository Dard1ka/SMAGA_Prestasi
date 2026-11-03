<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Providers\NavigationServiceProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The counts an open page asks for while it stays open (`notifikasi.jumlah`, GET /notifikasi/jumlah; D-127, lead's
 * mail / notification test of 2026-10-09): the bell of the top bar and the sidebar counts of the signed-in user's own
 * menus, so both follow a new notification or a changed queue without a page load. Asked by resources/js/app.js
 * ("live counts"), never by a person.
 *
 * - Acts on the signed-in user only: no ids in or out, nothing but numbers in the answer
 *   (`{"badges": {"akun.notifikasi": 1, "kesiswaan.usulan": 2}}`, NavigationServiceProvider::liveCounts()). The route
 *   carries the middleware of the Notifikasi page (auth, active, activated, staff.2fa); a queue count is only there
 *   for a user who may open that queue. Not audited (no state changes, nothing personal leaves).
 * - Own limit `throttle:30,1,lonceng`; `Cache-Control: no-store`.
 * - Never a page to land on: a request that does not ask for JSON (the address typed by hand, or the intended
 *   address after a login) is sent to the Notifikasi page. The script sends `Accept: application/json` and
 *   `X-Requested-With: XMLHttpRequest`, so a guest gets a plain 401 (no intended address is stored) and the session
 *   does not remember this address as the previous page.
 * - Not a page view: what an action flashed for the next page (a toast) stays for that page.
 */
final class NotificationCountController extends Controller
{
    /** Shortest time between two background checks of one page, whatever the setting says (seconds). */
    public const MIN_SECONDS = 30;

    /**
     * Longest time between two background checks (seconds). A browser timer takes its delay as a 32-bit number of
     * milliseconds: above about 24.8 days it wraps and can fire at once, again and again. An hour is far below that.
     */
    public const MAX_SECONDS = 3600;

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        if (! $request->expectsJson()) {
            return redirect()->route('notifikasi.index');
        }

        /** @var User $user */
        $user = $request->user();

        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return response()
            ->json(['badges' => NavigationServiceProvider::liveCounts($user)])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Seconds between two background checks of an open page (`config('sth.counts.poll_seconds')`,
     * STH_COUNTS_POLL_SECONDS): 0 = pages never ask by themselves, anything else is at least MIN_SECONDS and at
     * most MAX_SECONDS. So 0 is the only way to switch the checks off; a very large number is read as an hour.
     */
    public static function pollSeconds(): int
    {
        $seconds = (int) config('sth.counts.poll_seconds', 60);

        return $seconds > 0 ? min(self::MAX_SECONDS, max(self::MIN_SECONDS, $seconds)) : 0;
    }

    /**
     * The address the layout hands to the script, or null when this page must not ask: a guest or a preview user
     * (dev showcase), the background check switched off, or the route not registered.
     */
    public static function pollUrl(mixed $user): ?string
    {
        return $user instanceof User && self::pollSeconds() > 0 && Route::has('notifikasi.jumlah')
            ? route('notifikasi.jumlah')
            : null;
    }
}
