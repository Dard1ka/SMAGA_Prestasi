<?php

declare(strict_types=1);

use App\Mail\Transport\AllowlistTransport;
use App\Providers\AppServiceProvider;
use Illuminate\Mail\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;

/*
| Mailer "allowlist" (D-121, local development only): a mail whose recipients are all on STH_MAIL_ALLOWLIST goes
| out through the real mailer, every other mail is kept by the catcher (Mailpit). The demo data and the scheduler
| write to fictional addresses; those must never reach the mail provider.
*/

function allowlistMailers(string $recipients): void
{
    config([
        'mail.mailers.uji-kirim' => ['transport' => 'array'],
        'mail.mailers.uji-tangkap' => ['transport' => 'array'],
        'mail.mailers.allowlist' => [
            'transport' => 'allowlist',
            'deliver' => 'uji-kirim',
            'catch' => 'uji-tangkap',
            'recipients' => $recipients,
        ],
        'mail.from.address' => 'pengirim@example.test',
    ]);

    Mail::purge('allowlist');
    Mail::purge('uji-kirim');
    Mail::purge('uji-tangkap');
}

/** @return Collection<int, string> first recipient of every mail the given test mailer got */
function allowlistGot(string $mailer): Collection
{
    return Mail::mailer($mailer)->getSymfonyTransport()->messages()
        ->map(fn (SentMessage $sent): string => $sent->getEnvelope()->getRecipients()[0]->getAddress());
}

/** @param string|list<string> $to */
function allowlistSend(string|array $to, ?string $cc = null): void
{
    Mail::mailer('allowlist')->raw('Isi uji.', function (Message $message) use ($to, $cc): void {
        $message->to($to)->subject('Uji');

        if ($cc !== null) {
            $message->cc($cc);
        }
    });
}

it('delivers a mail for a listed address and keeps every other mail in the catcher', function (): void {
    allowlistMailers('tim.uji@example.test, tim.uji+*@example.test');

    allowlistSend('tim.uji@example.test');
    allowlistSend('tim.uji+guru@example.test');
    allowlistSend('nadia@sth.test');
    allowlistSend('humas@sth.test');

    expect(allowlistGot('uji-kirim')->all())->toBe(['tim.uji@example.test', 'tim.uji+guru@example.test'])
        ->and(allowlistGot('uji-tangkap')->all())->toBe(['nadia@sth.test', 'humas@sth.test']);
});

it('keeps a mail in the catcher as soon as one of its recipients is not listed', function (): void {
    allowlistMailers('tim.uji@example.test');

    allowlistSend(['tim.uji@example.test', 'nadia@sth.test']);
    allowlistSend('tim.uji@example.test', cc: 'guru@sth.test');

    expect(allowlistGot('uji-kirim'))->toHaveCount(0)
        ->and(allowlistGot('uji-tangkap'))->toHaveCount(2);
});

it('keeps everything in the catcher while the list is empty', function (): void {
    allowlistMailers('');

    allowlistSend('tim.uji@example.test');

    expect(allowlistGot('uji-kirim'))->toHaveCount(0)
        ->and(allowlistGot('uji-tangkap')->all())->toBe(['tim.uji@example.test']);
});

it('is the transport of the mailer named allowlist', function (): void {
    allowlistMailers('tim.uji@example.test');

    $transport = Mail::mailer('allowlist')->getSymfonyTransport();

    expect($transport)->toBeInstanceOf(AllowlistTransport::class)
        ->and((string) $transport)->toBe('allowlist(array, array)');
});

it('ships with Mailpit as the catcher and the smtp mailer for real delivery', function (): void {
    expect(config('mail.mailers.allowlist'))->toMatchArray([
        'transport' => 'allowlist',
        'deliver' => 'smtp',
        'catch' => 'mailpit',
    ])
        ->and(config('mail.mailers.mailpit'))->toMatchArray([
            'transport' => 'smtp',
            'host' => 'mailpit',
            'port' => 1025,
            'username' => null,
            'password' => null,
        ]);
});

it('refuses a deliver or catch mailer that is an allowlist mailer itself', function (string $key): void {
    allowlistMailers('tim.uji@example.test');
    config(["mail.mailers.allowlist.{$key}" => 'allowlist']);
    Mail::purge('allowlist');

    expect(fn () => Mail::mailer('allowlist'))->toThrow(InvalidArgumentException::class, $key);
})->with(['deliver', 'catch']);

it('cannot be built outside local development', function (string $env): void {
    allowlistMailers('tim.uji@example.test');
    app()->detectEnvironment(fn () => $env);

    expect(fn () => Mail::mailer('allowlist'))->toThrow(RuntimeException::class, 'APP_ENV=local');
})->with(['production', 'staging']);

it('refuses to boot with the allowlist mailer outside local development', function (string $env): void {
    app()->detectEnvironment(fn () => $env);
    config([
        'mail.default' => 'allowlist',
        'app.debug' => false,
        'fortify.sth.otp_test_mode' => false,
        'session.cookie' => '__Host-sth_session',
        'session.secure' => true,
        'session.domain' => null,
        'session.path' => '/',
    ]);

    expect(fn () => (new AppServiceProvider(app()))->guardUnsafeConfiguration())
        ->toThrow(RuntimeException::class, 'MAIL_MAILER allowlist');
})->with(['production', 'staging']);

it('boots with the allowlist mailer in local development', function (): void {
    app()->detectEnvironment(fn () => 'local');
    config(['mail.default' => 'allowlist', 'fortify.sth.otp_test_mode' => false]);

    (new AppServiceProvider(app()))->guardUnsafeConfiguration();

    expect(true)->toBeTrue();
});

it('says in sth:mail-test where the mail of a listed address goes', function (): void {
    allowlistMailers('tim.uji@example.test');
    config(['mail.default' => 'allowlist']);

    $this->artisan('sth:mail-test', ['to' => 'tim.uji@example.test'])
        ->expectsOutputToContain('Mailer: allowlist')
        ->expectsOutputToContain('Alamat ini ada di STH_MAIL_ALLOWLIST: email dikirim sungguhan lewat mailer uji-kirim.')
        ->expectsOutputToContain('Email uji diterima server untuk tim.uji@example.test.')
        ->assertSuccessful();

    expect(allowlistGot('uji-kirim')->all())->toBe(['tim.uji@example.test'])
        ->and(allowlistGot('uji-tangkap'))->toHaveCount(0);
});

it('says in sth:mail-test that an unlisted address only reaches Mailpit', function (): void {
    allowlistMailers('tim.uji@example.test, *@example.test');
    config(['mail.default' => 'allowlist']);

    $this->artisan('sth:mail-test', ['to' => 'nadia@sth.test'])
        ->expectsOutputToContain('Alamat ini tidak ada di STH_MAIL_ALLOWLIST: email hanya masuk Mailpit, tidak dikirim keluar.')
        ->expectsOutputToContain('Entri STH_MAIL_ALLOWLIST yang diabaikan: *@example.test')
        ->expectsOutputToContain('Email uji masuk Mailpit untuk nadia@sth.test.')
        ->doesntExpectOutputToContain('SPF')
        ->assertSuccessful();

    expect(allowlistGot('uji-tangkap')->all())->toBe(['nadia@sth.test'])
        ->and(allowlistGot('uji-kirim'))->toHaveCount(0);
});
