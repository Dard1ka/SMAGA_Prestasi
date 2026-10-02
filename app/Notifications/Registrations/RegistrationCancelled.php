<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/** To the other team members (and the mentor) when the leader cancels. */
class RegistrationCancelled extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Pendaftaran dibatalkan: '.$this->competitionName())
            ->greeting('Halo '.self::firstName($notifiable).',')
            ->line($this->message())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.cancelled',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Pendaftaran dibatalkan',
            'message' => $this->message(),
            'url' => self::link(['siswa.lomba-saya.index', 'siswa.beranda']),
        ];
    }

    private function message(): string
    {
        return $this->leaderName().' membatalkan pendaftaran '.$this->competitionName().'.';
    }
}
