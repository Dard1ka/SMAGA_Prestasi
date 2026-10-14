<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** To an invited team member: confirm or decline on the invite page (only the invitee can, §3m-3). */
class TeamInvitation extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Undangan tim: '.$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->leaderName().' mengajak kamu ikut tim '.$this->competitionName().'.')
            // D-148: a team resumed after (or shortly before) the deadline has its own three days to answer.
            ->line('Konfirmasi sebelum '.($this->registration->graceText() ?? 'batas pendaftaran')
                .' agar pendaftaran bisa diteruskan untuk persetujuan.')
            ->action('Lihat undangan', $this->url());
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
