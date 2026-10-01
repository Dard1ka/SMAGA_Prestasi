<?php

declare(strict_types=1);

namespace App\Notifications\Approval;

use App\Notifications\Registrations\RegistrationNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Kepala Sekolah rejected a registration with a required reason (ARCHITECTURE §6: email to students).
 * The reason is the Kepala Sekolah's own note; it is shown to the team as written.
 */
class RegistrationRejected extends RegistrationNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Pendaftaran lomba tidak disetujui')
            ->greeting('Halo '.self::firstName($notifiable).',')
            ->line('Kepala Sekolah tidak menyetujui pendaftaranmu di '.$this->competitionName().'.')
            ->line('Alasan: '.$this->reason())
            ->action('Lihat pendaftaran', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.rejected',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Pendaftaran tidak disetujui',
            'message' => $this->competitionName().'. Alasan: '.$this->reason(),
            'url' => $this->url(),
        ];
    }

    private function reason(): string
    {
        return trim((string) $this->registration->principal_note);
    }

    private function url(): string
    {
        return self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']);
    }
}
