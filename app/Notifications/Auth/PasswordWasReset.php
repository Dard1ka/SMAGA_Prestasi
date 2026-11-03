<?php

declare(strict_types=1);

namespace App\Notifications\Auth;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to the bound email after a password reset (docs/SECURITY.md §3b "Setelah reset"). No links, no NIS/NISN.
 */
class PasswordWasReset extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Kata sandi sudah diganti')
            ->greeting(MailGreeting::for($notifiable))
            ->line('Kata sandi akun SMAGA Talent Hub baru saja diganti lewat "Lupa kata sandi". Semua perangkat sudah dikeluarkan.')
            ->line('Jika tidak merasa menggantinya, segera hubungi Admin atau Kesiswaan.');
    }
}
