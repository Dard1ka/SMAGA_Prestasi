<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\Transport\AllowlistTransport;
use App\Mail\Transport\RecipientAllowlist;
use App\Notifications\Support\PlainMailMessage;
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
 * With MAIL_MAILER=allowlist (local development, D-121) it first says whether the address is on
 * STH_MAIL_ALLOWLIST, i.e. whether the mail really goes out or stays in Mailpit.
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

        // Local development (D-121): say which way this address goes, then describe that mailer.
        $caught = false;

        if (($settings['transport'] ?? null) === AllowlistTransport::DRIVER) {
            $allowlist = RecipientAllowlist::fromString((string) ($settings['recipients'] ?? ''));
            $caught = ! $allowlist->allows($to);
            $target = AllowlistTransport::mailerFor($settings, ! $caught);

            $this->line(match (true) {
                $caught && $allowlist->isOpen() => 'Alamat ini fiktif (misalnya @sth.test): email hanya masuk Mailpit, tidak dikirim keluar.',
                $caught => 'Alamat ini tidak ada di STH_MAIL_ALLOWLIST: email hanya masuk Mailpit, tidak dikirim keluar.',
                $allowlist->isOpen() => "STH_MAIL_ALLOWLIST terbuka: email dikirim sungguhan lewat mailer {$target}.",
                default => "Alamat ini ada di STH_MAIL_ALLOWLIST: email dikirim sungguhan lewat mailer {$target}.",
            });

            if ($allowlist->isOpen()) {
                $this->warn('STH_MAIL_ALLOWLIST berisi *: semua alamat nyata dikirim keluar, hanya alamat fiktif yang ditahan.');
            }

            if ($allowlist->rejected() !== []) {
                $this->warn('Entri STH_MAIL_ALLOWLIST yang diabaikan: '.implode(', ', $allowlist->rejected())
                    .' (tanda * hanya boleh di bagian nama sebelum @).');
            }

            if ($allowlist->openRefused()) {
                $this->warn('Tanda * (buka untuk semua alamat) tidak dipakai, karena ada entri lain yang diabaikan. Perbaiki entri itu dulu.');
            }

            $settings = (array) config("mail.mailers.{$target}", []);
        }

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
            PlainMailMessage::FOOTER,
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

        if ($caught) {
            $this->info("Email uji masuk Mailpit untuk {$to}.");
            $this->line('Buka Mailpit untuk melihatnya (stack dev: http://127.0.0.1:8025).');

            return self::SUCCESS;
        }

        $this->info("Email uji diterima server untuk {$to}.");
        $this->line('Cek kotak masuk dan folder spam; di header email, SPF, DKIM, dan DMARC harus "pass".');

        return self::SUCCESS;
    }
}
