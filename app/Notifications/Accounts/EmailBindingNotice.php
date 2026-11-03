<?php

declare(strict_types=1);

namespace App\Notifications\Accounts;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * K8 (docs/SECURITY.md §3a): sent when an email is bound at activation (to the new address) or changed (to the
 * new and the old address). Carries the signed, single-use "Bukan saya?" link (7 days) that freezes the account.
 * Mail only, sent on demand to an address (Notification::route('mail', …)), so the Action passes the greeting of the
 * account owner (MailGreeting: a student by the first name, staff by the full name). The text itself names the
 * owner by the first name only; never NIS/NISN.
 */
final class EmailBindingNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public const BOUND = 'bound';

    public const CHANGED_NEW = 'changed_new';

    public const CHANGED_OLD = 'changed_old';

    /**
     * The first line of the mail. A property with a default, not a promoted parameter: a notice that was queued
     * before this property existed still unserializes, and is then greeted by the first name.
     */
    public ?string $greeting = null;

    /**
     * @param  string  $firstName  the name the owner is called by (App\Support\FirstName); '' when unknown
     * @param  string|null  $greeting  MailGreeting::for() of the account owner
     */
    public function __construct(
        public readonly string $kind,
        public readonly string $firstName,
        public readonly string $newEmailMasked,
        public readonly string $notMeUrl,
        public readonly int $validDays,
        ?string $greeting = null,
    ) {
        $this->greeting = $greeting;
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
            ->greeting($this->greeting ?? MailGreeting::student($this->firstName));

        foreach ($this->lines() as $line) {
            $message->line($line);
        }

        return $message
            ->action('Bukan saya, bekukan akun', $this->notMeUrl)
            ->line('Link ini berlaku '.$this->validDays.' hari dan hanya bisa dipakai sekali. Jika memang melakukannya sendiri, abaikan email ini.');
    }

    public function subject(): string
    {
        return match ($this->kind) {
            self::CHANGED_OLD => 'Email akun SMAGA Talent Hub diganti',
            self::CHANGED_NEW => 'Email ini sekarang dipakai di SMAGA Talent Hub',
            default => 'Email ini terhubung ke SMAGA Talent Hub',
        };
    }

    /** @return list<string> */
    public function lines(): array
    {
        $owner = trim($this->firstName) === '' ? '' : ' atas nama '.trim($this->firstName);

        return match ($this->kind) {
            self::CHANGED_OLD => [
                'Email akun SMAGA Talent Hub baru saja diganti ke '.$this->newEmailMasked.'.',
                'Jika tidak merasa menggantinya, bekukan akun sekarang lalu hubungi Admin atau Kesiswaan.',
            ],
            self::CHANGED_NEW => [
                'Email ini baru saja dipasang di akun SMAGA Talent Hub'.$owner.'.',
                'Jika tidak merasa melakukannya, bekukan akun itu lalu hubungi Admin atau Kesiswaan.',
            ],
            default => [
                'Email ini baru saja dihubungkan ke akun SMAGA Talent Hub'.$owner.'.',
                'Jika tidak merasa melakukannya, bekukan akun itu lalu hubungi Admin atau Kesiswaan.',
            ],
        };
    }
}
