<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Accounts\EnforceLoginControls;
use App\Actions\Accounts\RecordFailedLogin;
use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\LoginIdentifier;
use App\Actions\Fortify\LoginResponse;
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
use Laravel\Fortify\Fortify;

/**
 * Fortify wiring (owner: W1-C). Only login, logout, password confirmation and TOTP 2FA are used from Fortify;
 * activation, OTP and forgot-password are Livewire screens (App\Livewire\Auth\*).
 */
class FortifyServiceProvider extends ServiceProvider
{
    /** Password confirmation (POST /user/confirm-password): tries per user and window. */
    public const CONFIRM_PASSWORD_ATTEMPTS = 5;

    public const CONFIRM_PASSWORD_DECAY_SECONDS = 60;

    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

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
        });

        // After "Keluar" + Back the old page becomes the intended address of the guest session: a different account
        // that logs in next goes to its beranda instead (App\Http\Middleware\RememberLoggedOutAccount).
        Event::listen(Logout::class, [RememberLoggedOutAccount::class, 'loggedOut']);
        Event::listen(Login::class, [RememberLoggedOutAccount::class, 'loggedIn']);

        // Audit rows for TOTP enabled / confirmed / disabled / recovery codes regenerated (Fortify's events).
        Event::subscribe(TwoFactorAuditSubscriber::class);
    }
}
