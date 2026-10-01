<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use Illuminate\Notifications\Messages\MailMessage;

/** To the Kepala Sekolah when a registration enters menunggu_kepsek (individu sent, or all members confirmed). */
class RegistrationAwaitingPrincipal extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pendaftaran lomba menunggu persetujuan')
            ->greeting('Yth. '.trim((string) ($notifiable->name ?? 'Kepala Sekolah')).',')
            ->line($this->summary())
            ->line('Mohon tinjau dan beri keputusan di SMAGA Talent Hub.')
            ->action('Buka persetujuan', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.awaiting_principal',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Menunggu persetujuan',
            'message' => $this->summary(),
            'url' => $this->url(),
        ];
    }

    private function summary(): string
    {
        $type = $this->registration->isTeam() ? 'tim' : 'individu';

        return $this->competitionName().': '.$this->leaderName().' ('.$type.').';
    }

    private function url(): string
    {
        return self::link(['kepsek.persetujuan.index', 'kepsek.beranda']);
    }
}
