<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: to the ketua when a student the revision invites answers (RespondToRevision). A decline ends the
 * revision (it no longer holds what the ketua asked for); the approved team stays.
 */
class RevisionAnswered extends RegistrationNotification
{
    public function __construct(Registration $registration, public string $memberName, public bool $accepted, public bool $ready = false)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject(($this->accepted ? 'Undangan revisi diterima: ' : 'Undangan revisi ditolak: ').$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->message())
            ->line($this->next())
            ->action('Lihat pendaftaran', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.revision_answered',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->accepted ? 'Undangan revisi diterima' : 'Undangan revisi ditolak',
            'message' => $this->message().' '.$this->next(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        return $this->accepted
            ? $this->memberName.' menerima undangan masuk tim '.$this->competitionName().'.'
            : $this->memberName.' menolak undangan masuk tim '.$this->competitionName().', jadi revisinya dibatalkan.';
    }

    private function next(): string
    {
        return match (true) {
            ! $this->accepted => 'Tim yang sudah disetujui tetap berlaku. Ajukan revisi baru kalau masih perlu.',
            $this->ready => 'Revisi sekarang menunggu keputusan Tata Usaha atau Kesiswaan.',
            default => 'Revisi masih menunggu jawaban siswa lain yang diundang.',
        };
    }

    private function url(): string
    {
        return self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index']);
    }
}
