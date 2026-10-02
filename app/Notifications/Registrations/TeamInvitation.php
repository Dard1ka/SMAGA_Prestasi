<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** To an invited team member: confirm or decline on the invite page (only the invitee can, §3m-3). */
class TeamInvitation extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Undangan tim: '.$this->competitionName())
            ->greeting('Halo '.self::firstName($notifiable).',')
            ->line($this->leaderName().' mengajak kamu ikut tim '.$this->competitionName().'.')
            ->line('Konfirmasi sebelum batas pendaftaran agar pendaftaran bisa diteruskan ke Kepala Sekolah.')
            ->action('Lihat undangan', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.invited',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Undangan tim',
            'message' => $this->leaderName().' mengajak kamu ikut tim '.$this->competitionName().'.',
            'url' => $this->url(),
        ];
    }

    private function url(): string
    {
        return self::link([['siswa.undangan.show', ['registration' => $this->registration->getKey()]]]);
    }
}
