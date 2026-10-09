<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Accounts\EnforceLoginControls;
use App\Actions\Accounts\RecordFailedLogin;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\GuardedTwoFactorLoginRequest;
use App\Actions\Fortify\KnownDevices;
use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginResponse;
use App\Actions\Fortify\TwoFactorChallenge;
use App\Http\Middleware\RememberLoggedOutAccount;
use App\Http\Middleware\RestrictTwoFactorToStaff;
use App\Models\User;
use App\Support\Audit\SecurityLog;
use App\Support\Audit\TwoFactorAuditSubscriber;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;
use Laravel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Throwable;

/**
 * Fortify wiring (owner: W1-C). Only login, logout, password confirmation and TOTP 2FA are used from Fortify;
 * activation, OTP and forgot-password are Livewire screens (App\Livewire\Auth\*).
 */
class FortifyServiceProvider extends ServiceProvider
{
    /** Password confirmation (POST /user/confirm-password): tries per user and window. */
    public const CONFIRM_PASSWORD_ATTEMPTS = 5;

    public const CONFIRM_PASSWORD_DECAY_SECONDS = 60;

    /** Path prefix of `files.cover`: such an address is dropped as the intended address at login (D-118). */
    public const COVER_PATH = '/berkas/sampul/';

    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);

        // QA10: every request to the TOTP challenge first asks TwoFactorChallenge whether it may go on.
        $this->app->bind(TwoFactorLoginRequest::class, GuardedTwoFactorLoginRequest::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn (Request $request) => view('auth.confirm-password', [
            'back' => self::confirmPasswordBack($request),
        ]));

        // NIS / NISN (students; also their email once activation is finished) or username / email (staff);
        // generic error, lockout, archived/disabled rejection.
        // B1: then K1 (expired initial/temporary password) and K3/K8 (frozen account), same generic error.
        $authenticate = function (Request $request): User {
            $user = app(AuthenticateUser::class)($request);

            app(EnforceLoginControls::class)->handle($request, $user);

            return $user;
        };

        Fortify::authenticateUsing($authenticate);

        // B1 — K3: a wrong password for a known, not-yet-activated student account counts towards the freeze.
        // AuthenticateUser fires Failed without a user for wrong passwords (with the user for refusals).
        Event::listen(Failed::class, function (Failed $event): void {
            $request = request();

            if ($event->user !== null || ! $request->routeIs('login.store')) {
                return;
            }

            $user = app(LoginIdentifier::class)->forRequest($request);

            if ($user instanceof User) {
                app(RecordFailedLogin::class)->handle($user);
            }
        });

        // Throttling happens in the `throttle:login` route middleware (AppServiceProvider); the progressive
        // lockout lives in AuthenticateUser. AuthenticateUser memoises its result, so running it from both
        // RedirectIfTwoFactorAuthenticatable and AttemptToAuthenticate checks the password only once.
        // TOTP is staff only (RestrictTwoFactorToStaff): once the password is accepted, a student account goes
        // straight on, so a secret left on it can never hold the student at /two-factor-challenge.
        Fortify::authenticateThrough(fn (Request $request) => [
            fn (Request $request, Closure $next) => RestrictTwoFactorToStaff::allows($authenticate($request))
                ? app(RedirectIfTwoFactorAuthenticatable::class)->handle($request, $next)
                : $next($request),
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        // The login field is not a user column, so confirm passwords against the stored hash directly.
        // POST /user/confirm-password gates the sensitive Admin actions and the TOTP setup (SECURITY §3m-9) and has
        // no route limiter: per user, 5 tries per minute. The try is counted before the hash check, so parallel
        // requests cannot get extra guesses; a correct password clears the count.
        Fortify::confirmPasswordsUsing(function ($user, ?string $password = null): bool {
            $key = 'confirm-password:'.$user->getAuthIdentifier();
            $attempts = RateLimiter::hit($key, self::CONFIRM_PASSWORD_DECAY_SECONDS);

            if ($attempts > self::CONFIRM_PASSWORD_ATTEMPTS) {
                throw ValidationException::withMessages([
                    'password' => AppServiceProvider::retryMessage(RateLimiter::availableIn($key)),
                ]);
            }

            if ($password !== null && Hash::check($password, $user->password)) {
                RateLimiter::clear($key);

                return true;
            }

            if ($attempts === self::CONFIRM_PASSWORD_ATTEMPTS) {
                SecurityLog::warning('security.password_confirm_locked', [
                    'user_id' => $user->getAuthIdentifier(),
                    'attempts' => $attempts,
                    'seconds' => self::CONFIRM_PASSWORD_DECAY_SECONDS,
                ]);
            }

            return false;
        });

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

            // QA10 review: this browser is now a known device of the account (LoginLockout lets it in while the
            // account is closed to unknown devices after many failures).
            if ($event->user instanceof User) {
                KnownDevices::remember($event->user);
            }
        });

        // After "Keluar" + Back the old page becomes the intended address of the guest session: a different account
        // that logs in next goes to its beranda instead (App\Http\Middleware\RememberLoggedOutAccount).
        Event::listen(Logout::class, [RememberLoggedOutAccount::class, 'loggedOut']);
        Event::listen(Login::class, [RememberLoggedOutAccount::class, 'loggedIn']);

        // A cover picture is never a page to land on (D-118). A catalog left open after the session ended keeps
        // asking for its <img> covers; the guest redirect stores the last one as the intended address, and the
        // next login would open a bare picture instead of the beranda.
        Event::listen(Login::class, function (): void {
            $intended = session('url.intended');
            $path = is_string($intended) ? (string) parse_url($intended, PHP_URL_PATH) : '';

            if (str_starts_with($path, self::COVER_PATH)) {
                session()->forget('url.intended');
            }
        });

        // Audit rows for TOTP enabled / confirmed / disabled / recovery codes regenerated and, since QA10, for every
        // wrong code at the challenge (Fortify's events).
        Event::subscribe(TwoFactorAuditSubscriber::class);

        // QA10: start time + password fingerprint of the challenge, wrong codes per account, forgotten after a sign-in.
        Event::listen(TwoFactorAuthenticationChallenged::class, [TwoFactorChallenge::class, 'started']);
        Event::listen(TwoFactorAuthenticationFailed::class, [TwoFactorChallenge::class, 'failed']);
        Event::listen(ValidTwoFactorAuthenticationCodeProvided::class, [TwoFactorChallenge::class, 'succeeded']);
    }

    /**
     * Where "Batal" on the password page goes (QA Q2-18, backlog #63): back to the screen that asked
     * (`url.intended`) when it is a page of this app (same scheme, host and port, as the error pages' "Kembali"
     * in ErrorPageServiceProvider) that does not ask for the password itself; else the page the user came from;
     * else the beranda.
     */
    public static function confirmPasswordBack(Request $request): string
    {
        $intended = $request->session()->get('url.intended');

        if (self::ownPage($request, $intended) && ! self::behindPassword($intended)) {
            return $intended;
        }

        // QA5: the page that asked is itself behind the password (Admin › Pengaturan, Pengguna, 2FA): going back
        // there only shows this page again. Back to where the user clicked (the Referer survives the redirect).
        $referer = $request->headers->get('referer');

        return self::ownPage($request, $referer) && ! self::behindPassword($referer) ? $referer : route('home');
    }

    /** @phpstan-assert-if-true string $url */
    private static function ownPage(Request $request, mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 2000 && str_starts_with($url, $request->getSchemeAndHttpHost().'/');
    }

    /** Does opening $url ask for the password again (the confirm page itself, or a route with `password.confirm`)? */
    private static function behindPassword(string $url): bool
    {
        try {
            $route = app('router')->getRoutes()->match(Request::create($url, 'GET'));
        } catch (Throwable) {
            return false;
        }

        return $route->getName() === 'password.confirm'
            || collect($route->gatherMiddleware())->contains(fn (mixed $m): bool => is_string($m) && str_starts_with($m, 'password.confirm'));
    }
}
