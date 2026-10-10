<?php

declare(strict_types=1);

namespace App\Notifications\Accounts;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * Notice to the account owner after a security change made on Akun › Keamanan (password changed, email
 * changed). Database row for the bell + (optionally) mail to the bound address. No secrets, no NIS/NISN.
 */
final class SecurityNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const PASSWORD_CHANGED = 'account.password_changed';

    public const EMAIL_CHANGED = 'account.email_changed';

    public function __construct(
        public readonly string $kind,
        public readonly string $title,
        public readonly string $body,
        public readonly bool $mail = true,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
    }

    public static function passwordChanged(): self
    {
        return new self(
            self::PASSWORD_CHANGED,
            'Kata sandi diganti',
            'Kata sandi akun baru saja diganti dan perangkat lain sudah dikeluarkan. Jika tidak merasa menggantinya, segera hubungi Admin atau Kesiswaan.',
        );
    }

    public static function emailChanged(string $newEmailMasked): self
    {
        return new self(
            self::EMAIL_CHANGED,
            'Email akun diganti',
            'Email akun sekarang '.$newEmailMasked.'. Jika tidak merasa menggantinya, segera hubungi Admin atau Kesiswaan.',
            false,
        );
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return $this->mail ? ['mail', 'database'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->title)
            ->greeting(MailGreeting::for($notifiable))
            ->line($this->body);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'body' => $this->body,
            'url' => Route::has('akun.keamanan') ? route('akun.keamanan') : null,
        ];
    }
}
