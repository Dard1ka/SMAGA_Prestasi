<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use Illuminate\Notifications\Messages\MailMessage;

/** To the team leader when an invited member confirms or declines. */
class MemberResponded extends RegistrationNotification
{
    public function __construct(Registration $registration, public string $memberName, public bool $accepted)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(($this->accepted ? 'Anggota ikut tim: ' : 'Anggota menolak: ').$this->competitionName())
            ->greeting('Halo '.self::firstName($notifiable).',')
            ->line($this->message());

        if (! $this->accepted) {
            $mail->line('Tim tetap lanjut bila anggota lain sudah konfirmasi. Kalau tim jadi kurang orang, batalkan lalu daftar ulang.');
        }

        return $mail->action('Lihat pendaftaran', $this->url())->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->accepted ? 'registration.member_confirmed' : 'registration.member_declined',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->accepted ? 'Anggota ikut tim' : 'Anggota menolak',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->memberName.($this->accepted ? ' ikut tim ' : ' menolak ikut tim ').$this->competitionName().'.';
    }

    private function url(): string
    {
        return self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index']);
    }
}
