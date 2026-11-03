<?php

declare(strict_types=1);

use App\Actions\Accounts\VerifyOtpCode;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Process;

/*
| OTP test mode (D-15, docs/SECURITY.md §3b): accepted codes only when APP_ENV=local AND STH_OTP_TEST_MODE=true,
| and the app refuses to boot when the flag is on in production (or any shared environment).
*/

function w1cProductionLikeConfig(): void
{
    config([
        'mail.default' => 'smtp',
        'app.debug' => false,
        'session.cookie' => '__Host-sth_session',
        'session.secure' => true,
        'session.domain' => null,
        'session.path' => '/',
    ]);
}

it('refuses to boot in production when STH_OTP_TEST_MODE is on', function (): void {
    app()->detectEnvironment(fn () => 'production');
    w1cProductionLikeConfig();
    config(['fortify.sth.otp_test_mode' => true]);

    expect(fn () => (new AppServiceProvider(app()))->boot())
        ->toThrow(RuntimeException::class, 'STH_OTP_TEST_MODE');
});

it('refuses to boot in staging when STH_OTP_TEST_MODE is on', function (): void {
    app()->detectEnvironment(fn () => 'staging');
    config(['fortify.sth.otp_test_mode' => true]);

    expect(fn () => (new AppServiceProvider(app()))->guardUnsafeConfiguration())
        ->toThrow(RuntimeException::class, 'STH_OTP_TEST_MODE');
});

it('boots in production when the flag is off', function (): void {
    app()->detectEnvironment(fn () => 'production');
    w1cProductionLikeConfig();
    config(['fortify.sth.otp_test_mode' => false]);

    (new AppServiceProvider(app()))->guardUnsafeConfiguration();

    expect(true)->toBeTrue();
});

it('refuses log/array mailers and debug mode in production', function (array $override): void {
    app()->detectEnvironment(fn () => 'production');
    w1cProductionLikeConfig();
    config(['fortify.sth.otp_test_mode' => false, ...$override]);

    expect(fn () => (new AppServiceProvider(app()))->guardUnsafeConfiguration())->toThrow(RuntimeException::class);
})->with([
    'log mailer' => [['mail.default' => 'log']],
    'array mailer' => [['mail.default' => 'array']],
    'debug' => [['app.debug' => true]],
    '__Host- cookie without Secure' => [['session.secure' => false]],
]);

it('activates test mode only for local + flag', function (string $env, bool $flag, bool $expected): void {
    app()->detectEnvironment(fn () => $env);
    config(['fortify.sth.otp_test_mode' => $flag]);

    expect(VerifyOtpCode::testModeActive())->toBe($expected);
})->with([
    ['local', true, true],
    ['local', false, false],
    ['testing', true, false],
    ['production', true, false],
]);

it('exits non-zero when a real process boots in production with the flag on', function (): void {
    $result = Process::path(base_path())
        ->env([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'STH_OTP_TEST_MODE' => 'true',
            'MAIL_MAILER' => 'smtp',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ])
        ->run(['php', 'artisan', 'about', '--only=environment']);

    expect($result->failed())->toBeTrue()
        ->and($result->output().$result->errorOutput())->toContain('STH_OTP_TEST_MODE');
});
