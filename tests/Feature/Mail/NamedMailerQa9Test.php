<?php

declare(strict_types=1);

use App\Mail\LocalMailGuard;
use App\Mail\OtpCodeMail;
use Illuminate\Contracts\Mail\Factory as MailFactory;
use Illuminate\Mail\Message;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/*
| QA9: the boot guard of D-121 only looks at the DEFAULT mailer. In allowlist mode the `smtp` mailer holds the
| provider's host and login (it is the allowlist's `deliver`), and a mailer can still be used by name: a mailable
| queued while MAIL_MAILER was smtp (Mailpit) remembers "smtp" and is retried after the switch, or someone calls
| Mail::mailer('smtp') in tinker. In APP_ENV=local such a mail is cancelled; the allowlist mailer itself and the
| mailers that deliver nothing real (Mailpit, log, array) are untouched, and other environments are not affected.
|
| The provider is never contacted: the Symfony transport of the mailer under test is swapped for an array transport
| after it is built from the (fictional) provider settings.
*/

/** The smtp mailer with a provider login, as in a local .env in allowlist mode. */
function qa9ProviderSettings(): array
{
    return [
        'mail.mailers.smtp.scheme' => null,
        'mail.mailers.smtp.url' => null,
        'mail.mailers.smtp.host' => 'smtp-relay.example.test',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'akun@smtp.example.test',
        'mail.mailers.smtp.password' => 'Sandi-SMTP-Fiktif-123',
        'mail.from.address' => 'pengirim@example.test',
    ];
}

/**
 * Builds the named mailer from the current config and swaps its transport for an array transport (what reached
 * "the provider" or "Mailpit").
 */
function qa9Capture(string $mailer): ArrayTransport
{
    Mail::purge($mailer);
    $transport = new ArrayTransport;
    Mail::mailer($mailer)->setSymfonyTransport($transport);

    return $transport;
}

/**
 * Local stack in allowlist mode: `smtp` = provider (captured), catcher = an array mailer, one listed address.
 *
 * @return array{provider: ArrayTransport, catcher: ArrayTransport}
 */
function qa9AllowlistStack(): array
{
    app()->detectEnvironment(fn () => 'local');
    config([
        ...qa9ProviderSettings(),
        'mail.default' => 'allowlist',
        'mail.mailers.uji-tangkap' => ['transport' => 'array'],
        'mail.mailers.allowlist' => [
            'transport' => 'allowlist',
            'deliver' => 'smtp',
            'catch' => 'uji-tangkap',
            'recipients' => 'tim.uji@example.test',
        ],
    ]);

    $provider = qa9Capture('smtp');
    $catcher = qa9Capture('uji-tangkap');
    Mail::purge('allowlist'); // built after the swap, so its `deliver` is the captured provider transport

    return ['provider' => $provider, 'catcher' => $catcher];
}

function qa9Raw(string $mailer, string $to): void
{
    Mail::mailer($mailer)->raw('Isi uji.', fn (Message $message) => $message->to($to)->subject('Uji'));
}

/** @return list<string> first recipient of every captured mail */
function qa9Recipients(ArrayTransport $transport): array
{
    return $transport->messages()
        ->map(fn (SentMessage $sent): string => $sent->getEnvelope()->getRecipients()[0]->getAddress())
        ->values()
        ->all();
}

it('cancels a mail sent by name through the smtp mailer that holds the provider login (QA9)', function (string $to): void {
    $stack = qa9AllowlistStack();
    Log::spy();

    qa9Raw('smtp', $to);

    expect($stack['provider']->messages())->toHaveCount(0)
        ->and($stack['catcher']->messages())->toHaveCount(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context = []): bool => ($context['mailer'] ?? null) === 'smtp'
            && ! str_contains($message.json_encode($context), $to),
    );
})->with([
    'a fictional demo address' => ['nadia@sth.test'],
    'even an address on the allowlist' => ['tim.uji@example.test'],
]);

it('cancels a queued mailable that remembers the smtp mailer when it is retried after the switch (QA9)', function (): void {
    $stack = qa9AllowlistStack();
    Log::spy();

    // Queued while MAIL_MAILER was smtp (Mailpit): Mailer::queue() stored the mailer name on the mailable.
    $mailable = (new OtpCodeMail('Nadia', '482913', OtpCodeMail::PURPOSE_VERIFY_EMAIL, 10))->to('nadia@sth.test')->mailer('smtp');
    $job = unserialize(serialize(new SendQueuedMailable($mailable)));

    $job->handle(app(MailFactory::class));

    expect($stack['provider']->messages())->toHaveCount(0);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context = []): bool => ($context['mailer'] ?? null) === 'smtp');
});

it('still delivers a listed address through the allowlist and keeps every other mail in the catcher (QA9)', function (): void {
    $stack = qa9AllowlistStack();
    Log::spy();

    qa9Raw('allowlist', 'tim.uji@example.test');
    qa9Raw('allowlist', 'nadia@sth.test');
    Mail::raw('Isi uji.', fn (Message $message) => $message->to('tim.uji@example.test')->subject('Uji')); // default mailer

    expect(qa9Recipients($stack['provider']))->toBe(['tim.uji@example.test', 'tim.uji@example.test'])
        ->and(qa9Recipients($stack['catcher']))->toBe(['nadia@sth.test']);
    Log::shouldNotHaveReceived('warning');
});

it('leaves a named mailer alone in local when it delivers nothing real (QA9)', function (string $mailer, array $config): void {
    app()->detectEnvironment(fn () => 'local');
    config([...qa9ProviderSettings(), 'mail.default' => 'allowlist', ...$config]);
    $captured = qa9Capture($mailer);
    Log::spy();

    qa9Raw($mailer, 'nadia@sth.test');

    expect(qa9Recipients($captured))->toBe(['nadia@sth.test']);
    Log::shouldNotHaveReceived('warning');
})->with([
    'the mailpit mailer' => ['mailpit', []],
    'smtp without a login (Mailpit through MAIL_HOST)' => ['smtp', ['mail.mailers.smtp.host' => 'mailpit', 'mail.mailers.smtp.username' => null, 'mail.mailers.smtp.password' => null]],
    'an array mailer' => ['uji-tangkap', ['mail.mailers.uji-tangkap' => ['transport' => 'array']]],
]);

it('does not touch a named mailer with a login outside local (QA9)', function (string $env): void {
    app()->detectEnvironment(fn () => $env);
    config([...qa9ProviderSettings(), 'mail.default' => 'smtp']);
    $provider = qa9Capture('smtp');
    Log::spy();

    qa9Raw('smtp', 'nadia@sth.test');

    expect(qa9Recipients($provider))->toBe(['nadia@sth.test']);
    Log::shouldNotHaveReceived('warning');
})->with(['testing', 'staging', 'production']);

it('judges a mailer with the same rule as the boot guard (QA9)', function (array $settings, bool $deliversEverything): void {
    expect(LocalMailGuard::deliversEverything($settings))->toBe($deliversEverything);
})->with([
    'smtp with a login' => [['transport' => 'smtp', 'host' => 'smtp-relay.example.test', 'username' => 'akun@smtp.example.test'], true],
    'smtp through a url' => [['transport' => 'smtp', 'url' => 'smtp://akun:sandi-fiktif@smtp-relay.example.test:587'], true],
    'ses' => [['transport' => 'ses'], true],
    'failover' => [['transport' => 'failover', 'mailers' => ['smtp', 'log']], true],
    'smtp without a login' => [['transport' => 'smtp', 'host' => 'mailpit', 'username' => null], false],
    'allowlist' => [['transport' => 'allowlist', 'deliver' => 'smtp'], false],
    'log' => [['transport' => 'log'], false],
    'array' => [['transport' => 'array'], false],
    'an unknown mailer (no settings)' => [[], false],
]);
