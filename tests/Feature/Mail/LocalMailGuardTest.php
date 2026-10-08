<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Process;

/*
| A local stack mails by itself (scheduler, demo data, factories) and writes to fictional addresses. So with
| APP_ENV=local a real mail provider may only be reached through the allowlist mailer (D-121): the app refuses to
| boot when the default mailer would deliver every mail. This also covers the E2E project, which reads the same .env.
| Production is not touched: there an smtp mailer with a login is the normal case.
*/

function localMailGuard(string $env, array $config): void
{
    app()->detectEnvironment(fn () => $env);
    config([
        'fortify.sth.otp_test_mode' => false,
        'app.debug' => false,
        'session.cookie' => '__Host-sth_session',
        'session.secure' => true,
        'session.domain' => null,
        'session.path' => '/',
        'mail.mailers.smtp.username' => null,
        'mail.mailers.smtp.password' => null,
        'mail.mailers.smtp.url' => null,
        ...$config,
    ]);

    (new AppServiceProvider(app()))->guardUnsafeConfiguration();
}

it('refuses to boot in local when the default mailer would deliver every mail', function (array $config): void {
    expect(fn () => localMailGuard('local', $config))
        ->toThrow(RuntimeException::class, 'MAIL_MAILER=allowlist');
})->with([
    'smtp with a login' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp-relay.example.test', 'mail.mailers.smtp.username' => 'akun@smtp.example.test']],
    'smtp through MAIL_URL' => [['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'smtp://akun:sandi-fiktif@smtp-relay.example.test:587']],
    'ses' => [['mail.default' => 'ses']],
    'postmark' => [['mail.default' => 'postmark']],
    'resend' => [['mail.default' => 'resend']],
    'sendmail' => [['mail.default' => 'sendmail']],
    'failover' => [['mail.default' => 'failover']],
]);

it('boots in local with a mail catcher, a mailer that sends nothing, or the allowlist', function (array $config): void {
    localMailGuard('local', $config);

    expect(true)->toBeTrue();
})->with([
    'smtp without a login (Mailpit)' => [['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit']],
    'the mailpit mailer' => [['mail.default' => 'mailpit']],
    'log' => [['mail.default' => 'log']],
    'array' => [['mail.default' => 'array']],
    'allowlist over an smtp mailer with a login' => [['mail.default' => 'allowlist', 'mail.mailers.smtp.username' => 'akun@smtp.example.test']],
    'a mailer name that does not exist (Laravel reports that itself)' => [['mail.default' => 'tidak-ada']],
]);

it('leaves an smtp mailer with a login alone outside local', function (string $env): void {
    localMailGuard($env, [
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp-relay.example.test',
        'mail.mailers.smtp.username' => 'akun@smtp.example.test',
    ]);

    expect(true)->toBeTrue();
})->with(['production', 'staging', 'testing']);

it('exits non-zero when a real local process boots with a provider login under the smtp mailer', function (): void {
    $env = [
        'APP_ENV' => 'local',
        'APP_DEBUG' => 'false',
        'STH_OTP_TEST_MODE' => 'false',
        'MAIL_MAILER' => 'smtp',
        'MAIL_HOST' => 'smtp-relay.example.test',
        'MAIL_USERNAME' => 'akun@smtp.example.test',
        'MAIL_PASSWORD' => 'Sandi-SMTP-Fiktif-123',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'array',
    ];

    $refused = Process::path(base_path())->env($env)->run(['php', 'artisan', 'about', '--only=environment']);
    $allowed = Process::path(base_path())->env([...$env, 'MAIL_MAILER' => 'allowlist'])->run(['php', 'artisan', 'about', '--only=environment']);

    expect($refused->failed())->toBeTrue()
        ->and($refused->output().$refused->errorOutput())->toContain('MAIL_MAILER=allowlist')
        ->and($refused->output().$refused->errorOutput())->not->toContain('Sandi-SMTP-Fiktif-123')
        ->and($allowed->successful())->toBeTrue();
});
