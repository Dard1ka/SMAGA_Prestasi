<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * To the ketua when unanswered team invitations expired at the registration deadline (B3, backlog #9).
 * `ready`: the remaining team (≥ 2, all confirmed) went on to the Kepala Sekolah; otherwise the team is
 * too small (D-36) and the ketua should cancel the registration.
 */
class InvitationsExpired extends RegistrationNotification
{
    public function __construct(Registration $registration, public int $expired, public bool $ready, public int $team)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->ready ? 'Tim diteruskan ke Kepala Sekolah: ' : 'Tim kurang anggota: ').$this->competitionName())
            ->greeting('Halo '.self::firstName($notifiable).',')
            ->line($this->message())
            ->action('Lihat pendaftaran', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.invitations_expired',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->ready ? 'Tim diteruskan ke Kepala Sekolah' : 'Tim kurang anggota',
            'message' => $this->message(),
            'url' => $this->url(),
            'ready' => $this->ready,
        ];
    }

    public function message(): string
    {
        $what = $this->expired.' undangan tim '.$this->competitionName().' tidak dijawab sampai batas pendaftaran dan sudah tidak berlaku.';

        return $this->ready
            ? $what.' Tim '.$this->team.' orang diteruskan ke Kepala Sekolah.'
            : $what.' Tim kurang dari 2 orang, jadi pendaftaran tidak bisa diteruskan. Batalkan pendaftaran ini di Lomba saya.';
    }

    private function url(): string
    {
        return self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index']);
    }
}
