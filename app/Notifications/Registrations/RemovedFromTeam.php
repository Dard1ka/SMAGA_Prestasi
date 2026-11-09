<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: to an anggota who is no longer in the team. Before the approval the ketua took the student out
 * (ChangeRegistration, $deciderRole null); after it a revision that removes the student was approved by Tata Usaha or
 * Kesiswaan (ApproveRevision, $deciderRole = that role). Minimal content: lomba, ketua, a link (SECURITY §3m-3).
 */
class RemovedFromTeam extends RegistrationNotification
{
    public function __construct(Registration $registration, public ?string $deciderRole = null)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject('Kamu tidak lagi di tim: '.$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->message());

        if ($this->deciderRole === null) {
            $mail->line('Kamu tetap bisa mendaftar lomba ini sendiri atau dengan tim lain selama pendaftaran masih dibuka.');
        }

        return $mail->action('Lihat lomba', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.member_removed',
            'registration_id' => $this->registration->getKey(),
            'title' => 'Kamu tidak lagi di tim',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->deciderRole === null
            ? $this->leaderName().' mengeluarkanmu dari tim '.$this->competitionName().'.'
            : 'Revisi tim '.$this->competitionName().' disetujui '.$this->deciderRole.': kamu tidak lagi menjadi anggota tim ini.';
    }

    private function url(): string
    {
        return self::link([['siswa.lomba.show', ['competition' => $this->registration->competition_id]], 'siswa.lomba.index']);
    }
}
