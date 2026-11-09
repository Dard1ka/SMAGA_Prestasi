<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: to a student a revision of an approved team invites (RequestRevision). The student answers on Siswa ›
 * Undangan revisi (only that student can, RegistrationRevisionPolicy::respond); the team changes only once Tata Usaha or
 * Kesiswaan approves the revision.
 */
class RevisionInvitation extends RegistrationNotification
{
    public function __construct(Registration $registration, public string $revisionId)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject('Undangan tim: '.$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->message())
            ->line('Terima undangan ini dulu. Setelah itu Tata Usaha atau Kesiswaan memutuskan perubahan timnya.')
            ->action('Lihat undangan', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.revision_invited',
            'registration_id' => $this->registration->getKey(),
            'revision_id' => $this->revisionId,
            'title' => 'Undangan tim',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->leaderName().' mengajak kamu masuk tim '.$this->competitionName().' yang sudah disetujui sekolah.';
    }

    private function url(): string
    {
        return self::link([['siswa.undangan.revisi', ['revision' => $this->revisionId]], 'siswa.beranda']);
    }
}
