<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain test email through the configured mailer (docs/DEPLOY.md §9): proves SMTP host, port, TLS,
 * credentials and the noreply sender before go-live, without touching any account or OTP.
 *
 *   docker compose -f docker/compose.prod.yaml exec app php artisan sth:mail-test penerima@sman3-smg.sch.id
 *
 * Prints mailer, server and sender; never the SMTP username or password. Sends synchronously (no queue), so the
 * transport error is shown right here. Exit code 0 = accepted by the mail server, 1 = invalid address or failure.
 */
final class MailTest extends Command
{
    protected $signature = 'sth:mail-test
        {to : Alamat email penerima uji}';

    protected $description = 'Kirim satu email uji lewat pengaturan MAIL_* (cek SMTP noreply)';

    public const SUBJECT = 'Uji email SMAGA Talent Hub';

    public function handle(): int
    {
        $to = trim((string) $this->argument('to'));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Alamat email tidak valid.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $settings = (array) config("mail.mailers.{$mailer}", []);
        $from = (string) config('mail.from.address');

        $this->line("Mailer: {$mailer}");

        if (($settings['transport'] ?? null) === 'smtp') {
            $this->line(sprintf(
                'Server: %s:%s (%s)',
                (string) ($settings['host'] ?? '?'),
                (string) ($settings['port'] ?? '?'),
                filled($settings['scheme'] ?? null) ? (string) $settings['scheme'] : 'skema otomatis',
            ));
            $this->line('Login SMTP: '.(filled($settings['username'] ?? null) ? 'ya (kata sandi tidak ditampilkan)' : 'tidak'));
        }

        $this->line("Pengirim: {$from}");

        if (in_array($settings['transport'] ?? $mailer, ['log', 'array'], true)) {
            $this->warn('Mailer ini tidak mengirim email sungguhan (log/array).');
        }

        $body = implode("\n", [
            'Ini email uji dari SMAGA Talent Hub (perintah sth:mail-test).',
            'Kalau email ini sampai, pengaturan email noreply sudah benar.',
            '',
            'Dikirim: '.now()->format('d-m-Y H:i').' WIB · '.config('app.url'),
            'Email ini dikirim otomatis, tidak perlu dibalas.',
        ]);

        try {
            Mail::mailer($mailer)->raw($body, function (Message $message) use ($to): void {
                $message->to($to)->subject(self::SUBJECT);
            });
        } catch (Throwable $e) {
            $this->error('Gagal mengirim: '.$e->getMessage());
            $this->line('Periksa MAIL_HOST, MAIL_PORT, MAIL_SCHEME, kredensial, dan jaringan keluar (egress) kontainer.');

            return self::FAILURE;
        }

        $this->info("Email uji diterima server untuk {$to}.");
        $this->line('Cek kotak masuk dan folder spam; di header email, SPF, DKIM, dan DMARC harus "pass".');

        return self::SUCCESS;
    }
}
