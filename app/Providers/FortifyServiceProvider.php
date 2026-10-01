<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\AuthenticateUser;
use App\Actions\Fortify\LoginResponse;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\ServiceProvider;
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
    public function register(): void
    {
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        // NIS / NISN (students) or email (staff); generic error, lockout, archived/disabled rejection.
        Fortify::authenticateUsing(fn (Request $request) => app(AuthenticateUser::class)($request));

        // Throttling happens in the `throttle:login` route middleware (AppServiceProvider); the progressive
        // lockout lives in AuthenticateUser. AuthenticateUser memoises its result, so running it from both
        // RedirectIfTwoFactorAuthenticatable and AttemptToAuthenticate checks the password only once.
        Fortify::authenticateThrough(fn (Request $request) => [
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        // The login field is not a user column, so confirm passwords against the stored hash directly.
        Fortify::confirmPasswordsUsing(
            fn ($user, ?string $password = null): bool => $password !== null && Hash::check($password, $user->password)
        );

        Event::listen(Login::class, function (Login $event): void {
            $event->user->forceFill(['last_login_at' => now()])->saveQuietly();
        });
    }
}
