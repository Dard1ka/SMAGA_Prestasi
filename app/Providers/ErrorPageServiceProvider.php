<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Error pages in the app's look (M2-C, QA Q1-07, Q2-30, Q2-03, Q2-17).
 *
 * The pages are resources/views/errors/{401,403,404,405,419,429,500,503,4xx,5xx}.blade.php; Laravel picks the
 * view by status code, also with APP_DEBUG=true (so a wrong-method request no longer builds the debug page).
 * They share errors/layout.blade.php, which gets its two links from here:
 * - `home`  "Kembali ke beranda": /beranda for a signed-in user, else the public home;
 * - `back`  "Kembali": the page the visitor came from, only when it is a page of this app.
 * An error page must still render while the database is down, so nothing here opens a connection for a 5xx.
 *
 * Second job: the first page after login is the "intended" address somebody opened before logging in. When
 * that page answers 403 or 404 for the account that logged in, the visitor goes to their beranda instead
 * (afterLogin(), called from the exception renderer in bootstrap/app.php).
 */
final class ErrorPageServiceProvider extends ServiceProvider
{
    /** Session flash key: set on login, so it is readable on exactly the next request. */
    public const AFTER_LOGIN = 'sth.after_login';

    /** Statuses without "Kembali": going back would only repeat the same answer. */
    private const NO_BACK = [429, 503];

    public function boot(): void
    {
        View::composer('errors.layout', function (ViewContract $view): void {
            $view->with(self::links(request(), (int) ($view->getData()['code'] ?? 500)));
        });

        Event::listen(Login::class, function (): void {
            $request = request();

            if ($request->hasSession()) {
                $request->session()->flash(self::AFTER_LOGIN, true);
            }
        });
    }

    /**
     * @return array{home: string, back: ?string}
     */
    public static function links(Request $request, int $status): array
    {
        return [
            'home' => self::signedIn($request, $status) ? self::url('home', '/beranda') : self::url('public.home', '/'),
            'back' => in_array($status, self::NO_BACK, true) ? null : self::previousPage($request),
        ];
    }

    /**
     * 403/404 on the first GET after login → redirect to the beranda; null = render the error page as usual.
     */
    public static function afterLogin(HttpExceptionInterface $e, Request $request): ?RedirectResponse
    {
        if (! in_array($e->getStatusCode(), [403, 404], true)
            || ! $request->isMethod('GET')
            || $request->expectsJson()
            || ! $request->hasSession()
            || $request->session()->get(self::AFTER_LOGIN) !== true
            || ! Auth::hasUser()
            || ! Route::has('home')
            || $request->routeIs('home')) {
            return null;
        }

        return redirect()->route('home');
    }

    /**
     * Is somebody signed in? Uses the user the request already resolved; asks the guard only when a session
     * was started (routes outside the `web` group have none) and never for a 5xx, which may be the database.
     */
    private static function signedIn(Request $request, int $status): bool
    {
        if (Auth::hasUser()) {
            return true;
        }

        if ($status >= 500 || ! $request->hasSession() || ! $request->session()->isStarted()) {
            return false;
        }

        return (bool) rescue(fn (): bool => Auth::check(), false, false);
    }

    /**
     * The Referer, when it is another page of this app (same scheme, host and port). Anything else, and the
     * address of the error itself, gives null: the layout then shows no "Kembali".
     */
    private static function previousPage(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');

        if ($referer === '' || strlen($referer) > 2000 || ! str_starts_with($referer, $request->getSchemeAndHttpHost().'/')) {
            return null;
        }

        $samePath = rtrim((string) parse_url($referer, PHP_URL_PATH), '/') === rtrim($request->getPathInfo(), '/');
        $sameQuery = (string) parse_url($referer, PHP_URL_QUERY) === (string) $request->server->get('QUERY_STRING');

        return $samePath && $sameQuery ? null : $referer;
    }

    private static function url(string $route, string $path): string
    {
        return Route::has($route) ? route($route) : url($path);
    }
}
