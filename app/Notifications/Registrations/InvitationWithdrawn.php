<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** To an invited student whose team invitation the ketua withdrew before it was answered (QA6, WithdrawInvitation). */
class InvitationWithdrawn extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Undangan tim dibatalkan: '.$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->message())
            ->line('Kamu tidak perlu menjawab undangan itu lagi. Kamu tetap bisa mendaftar lomba ini sendiri atau dengan tim lain selama pendaftaran masih dibuka.')
            ->action('Lihat lomba', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.invitation_withdrawn',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Undangan tim dibatalkan',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->leaderName().' membatalkan undanganmu untuk ikut tim '.$this->competitionName().'.';
    }

    private function url(): string
    {
        return self::link([['siswa.lomba.show', ['competition' => $this->registration->competition_id]], 'siswa.lomba.index']);
    }
}
