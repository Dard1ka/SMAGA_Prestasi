<?php

declare(strict_types=1);

namespace App\Notifications\Accounts;

use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * K8 (docs/SECURITY.md §3a): sent when an email is bound at activation (to the new address) or changed (to the
 * new and the old address). Carries the signed, single-use "Bukan saya?" link (7 days) that freezes the account.
 * First name only; no NIS/NISN. Mail only, sent on demand to an address (Notification::route('mail', …)).
 */
final class EmailBindingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const BOUND = 'bound';

    public const CHANGED_NEW = 'changed_new';

    public const CHANGED_OLD = 'changed_old';

    public function __construct(
        public readonly string $kind,
        public readonly string $firstName,
        public readonly string $newEmailMasked,
        public readonly string $notMeUrl,
        public readonly int $validDays,
    ) {
        $this->onQueue((string) config('fortify.sth.mail_queue', 'mail'));
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting('Halo '.$this->firstName.',');

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message
            ->action('Bukan saya, bekukan akun', $this->notMeUrl)
            ->line('Link ini berlaku '.$this->validDays.' hari dan hanya bisa dipakai sekali. Jika memang kamu, abaikan email ini.')
            ->salutation('SMAGA Talent Hub');
    }

    public function subject(): string
    {
        return match ($this->kind) {
            self::CHANGED_OLD => 'Email akun SMAGA Talent Hub kamu diganti',
            self::CHANGED_NEW => 'Email ini sekarang dipakai di SMAGA Talent Hub',
            default => 'Email kamu terhubung ke SMAGA Talent Hub',
        };
    }

    /** @return list<string> */
    public function lines(): array
    {
        return match ($this->kind) {
            self::CHANGED_OLD => [
                'Email akun SMAGA Talent Hub kamu baru saja diganti ke '.$this->newEmailMasked.'.',
                'Jika bukan kamu yang menggantinya, bekukan akun sekarang lalu hubungi Admin atau Kesiswaan.',
            ],
            self::CHANGED_NEW => [
                'Email ini baru saja dipasang di akun SMAGA Talent Hub atas nama '.$this->firstName.'.',
                'Jika bukan kamu, bekukan akun itu lalu hubungi Admin atau Kesiswaan.',
            ],
            default => [
                'Email ini baru saja dihubungkan ke akun SMAGA Talent Hub atas nama '.$this->firstName.'.',
                'Jika bukan kamu, bekukan akun itu lalu hubungi Admin atau Kesiswaan.',
            ],
        };
    }
}
