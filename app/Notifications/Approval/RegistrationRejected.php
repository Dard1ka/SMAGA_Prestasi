<?php

declare(strict_types=1);

namespace App\Notifications\Approval;

use App\Models\Registration;
use App\Models\User;
use App\Notifications\Registrations\RegistrationNotification;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Support\StaffRoleLabel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An approver (Kepala Sekolah or Tata Usaha, D-150) rejected a registration with a required reason (ARCHITECTURE §6:
 * email to students; lead 2026-10-02: the guru pembimbing hears about it too). Two audiences: `siswa` (team members)
 * and `guru` (pembimbing, opens the registration in the guru area). The reason is the approver's own note; it is
 * shown as written. The sentence names the role that decided ("sekolah" for a notice without one, D-150). Never
 * NIS/NISN/contact data (SECURITY §3m-3).
 */
class RegistrationRejected extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru'];

    /** D-150: the role that decided ("Kepala Sekolah", "Tata Usaha"); null for a notice queued before D-150. */
    public ?string $deciderRole = null;

    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience, ?User $decider = null): self
    {
        $notification = new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'siswa');
        $notification->deciderRole = $decider !== null ? StaffRoleLabel::forDecision($decider) : null;

        return $notification;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $role = $this->deciderRole ?? StaffRoleLabel::FALLBACK;

        return (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'guru' ? MailGreeting::staff($notifiable) : MailGreeting::student($notifiable))
            ->line($this->audience === 'guru'
                ? 'Pendaftaran binaan di '.$this->competitionName().' ('.$this->leaderName().') tidak disetujui '.$role.'.'
                : 'Pendaftaranmu di '.$this->competitionName().' tidak disetujui '.$role.'.')
            ->line('Alasan: '.$this->reason())
            ->action('Lihat pendaftaran', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.rejected',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->audience === 'guru' ? $this->subject() : 'Pendaftaran tidak disetujui',
            'message' => $this->audience === 'guru'
                ? $this->competitionName().' ('.$this->leaderName().'). Alasan: '.$this->reason()
                : $this->competitionName().'. Alasan: '.$this->reason(),
            'url' => $this->url(),
        ];
    }

    private function subject(): string
    {
        return $this->audience === 'guru' ? 'Pendaftaran binaan tidak disetujui' : 'Pendaftaran lomba tidak disetujui';
    }

    private function reason(): string
    {
        return trim((string) $this->registration->principal_note);
    }

    private function url(): string
    {
        return $this->audience === 'guru'
            ? self::link([['guru.binaan.show', ['registration' => $this->registration->getKey()]], 'guru.beranda'])
            : self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']);
    }
}
