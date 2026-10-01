<?php

declare(strict_types=1);

use App\Console\Commands\MailTest;
use Illuminate\Support\Collection;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

/** @return Collection<int, SentMessage> */
function mailTestSentMessages(): Collection
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages();
}

it('sends one plain test email through the configured mailer', function (): void {
    config(['mail.default' => 'array', 'mail.from.address' => 'noreply@sman3-smg.sch.id']);

    $this->artisan('sth:mail-test', ['to' => 'uji@example.test'])
        ->expectsOutputToContain('Mailer: array')
        ->expectsOutputToContain('Pengirim: noreply@sman3-smg.sch.id')
        ->assertSuccessful();

    $messages = mailTestSentMessages();
    expect($messages)->toHaveCount(1);

    $email = $messages->first()->getOriginalMessage();
    expect($email)->toBeInstanceOf(Email::class)
        ->and($email->getTo()[0]->getAddress())->toBe('uji@example.test')
        ->and($email->getFrom()[0]->getAddress())->toBe('noreply@sman3-smg.sch.id')
        ->and($email->getSubject())->toBe(MailTest::SUBJECT)
        ->and($email->getTextBody())->toContain('sth:mail-test');
});

it('rejects an invalid address and sends nothing', function (): void {
    config(['mail.default' => 'array']);

    $this->artisan('sth:mail-test', ['to' => 'bukan-email'])
        ->expectsOutputToContain('Alamat email tidak valid.')
        ->assertFailed();

    expect(mailTestSentMessages())->toHaveCount(0);
});

it('reports an SMTP failure without printing the credentials', function (): void {
    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => '127.0.0.1',
        'mail.mailers.smtp.port' => 9,
        'mail.mailers.smtp.scheme' => 'smtp',
        'mail.mailers.smtp.username' => 'noreply@sman3-smg.sch.id',
        'mail.mailers.smtp.password' => 'Sandi-SMTP-Fiktif-123',
        'mail.mailers.smtp.timeout' => 3,
    ]);

    $this->artisan('sth:mail-test', ['to' => 'uji@example.test'])
        ->expectsOutputToContain('Server: 127.0.0.1:9 (smtp)')
        ->expectsOutputToContain('Login SMTP: ya (kata sandi tidak ditampilkan)')
        ->expectsOutputToContain('Gagal mengirim:')
        ->doesntExpectOutputToContain('Sandi-SMTP-Fiktif-123')
        ->assertFailed();
});
