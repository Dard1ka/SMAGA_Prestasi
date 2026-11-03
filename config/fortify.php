<?php

use Laravel\Fortify\Features;

/*
| SMAGA Talent Hub auth configuration (owner: W1-C).
| Login is custom: students use NIS or NISN (or their bound email once activation is finished), staff use their
| username or email (see App\Actions\Fortify\AuthenticateUser, LoginIdentifier).
| Registration, Fortify's link-based password reset, profile/password update endpoints and passkeys are OFF:
| accounts come from the student import / Admin, and password recovery uses a 6-digit OTP (Livewire).
*/

// STH_STAFF_2FA (default true): false = TOTP 2FA does not exist in the app at all (read once, used twice below).
$staffTwoFactor = (bool) env('STH_STAFF_2FA', true);

return [

    'guard' => 'web',

    'passwords' => 'users',

    // Name of the login form field. It holds a NIS, a NISN (digits), an email or a staff username.
    'username' => 'identifier',

    'email' => 'email',

    // Normalisation happens in App\Actions\Fortify\LoginIdentifier, not in Fortify's pipeline.
    'lowercase_usernames' => false,

    // Named route `home` (routes/web.php) sends each role to its area.
    'home' => '/beranda',

    'prefix' => '',

    'domain' => null,

    'middleware' => ['web'],

    // Rate limiters are defined in App\Providers\AppServiceProvider::configureRateLimiting().
    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
    ],

    // Fortify still registers GET routes for login, 2FA challenge and password confirmation.
    'views' => true,

    'passkeys' => [
        'relying_party_id' => parse_url((string) config('app.url'), PHP_URL_HOST),
        'allowed_origins' => [config('app.url')],
        'timeout' => 60000,
    ],

    // Without the feature Fortify registers no `two-factor.*` route and no /two-factor-challenge (STH_STAFF_2FA).
    'features' => $staffTwoFactor ? [
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ] : [],

    /*
    |--------------------------------------------------------------------------
    | STH settings (read with config('fortify.sth.*'))
    |--------------------------------------------------------------------------
    | otp_test_mode: accept any 6 digits as OTP. Effective ONLY when APP_ENV=local AND this flag is true
    |   (App\Actions\Accounts\VerifyOtpCode::testModeActive()). AppServiceProvider refuses to boot when it is
    |   true outside local/testing (docs/SECURITY.md §3b, D-15).
    | staff_2fa: TOTP 2FA exists at all (env STH_STAFF_2FA, default true = the security baseline, SECURITY §3a). False,
    |   for every account: no Fortify 2FA routes, no /two-factor-challenge, no /akun/2fa (404), a login never asks for a
    |   code (also for an account with a confirmed secret), `staff.2fa` lets everyone through whatever
    |   require_staff_2fa says, and no screen mentions 2FA. Read through EnsureStaffTwoFactor::featureEnabled().
    |   Routes are registered at boot: after a change, reload.bat.
    | require_staff_2fa: staff roles (everything except siswa) must confirm TOTP 2FA before entering their area. Only
    |   read while staff_2fa is true.
    */
    'sth' => [
        'staff_2fa' => $staffTwoFactor,

        'otp_test_mode' => (bool) env('STH_OTP_TEST_MODE', false),

        // Default: on everywhere except APP_ENV=local (set STH_REQUIRE_STAFF_2FA=true locally to try the flow).
        'require_staff_2fa' => (bool) env('STH_REQUIRE_STAFF_2FA', env('APP_ENV') !== 'local'),

        'otp' => [
            'ttl_minutes' => 10,
            'max_attempts' => 5,
            'resend_cooldown_seconds' => 60,
            // Queue names match the production worker (--queue=otp,mail,default, docs/dev-notes/W1-E.md).
            'queue' => env('STH_OTP_QUEUE', 'otp'),
        ],

        'mail_queue' => env('STH_MAIL_QUEUE', 'mail'),

        // Progressive lockout after N failed logins on one account bucket: [failures => lockout seconds].
        'login_lockout' => [
            5 => 60,
            10 => 900,
            20 => 3600,
        ],
    ],

];
