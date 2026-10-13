<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The ketua cancelled the registration. Three audiences (lead, 2026-10-02): `siswa` (the other team members),
 * `guru` (pembimbing, opens the registration in the guru area) and `kepsek` (everyone who was asked to approve
 * it: Kepala Sekolah and Tata Usaha since D-150, each linked to its own Persetujuan address; only sent when the
 * registration had already reached menunggu_kepsek). Names and the lomba only (§3m-3).
 */
class RegistrationCancelled extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru', 'kepsek'];

    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience): self
    {
        return new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'siswa');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? MailGreeting::student($notifiable) : MailGreeting::staff($notifiable))
            ->line($this->message());

        if ($this->audience === 'kepsek') {
            $mail->line('Pendaftaran ini tidak perlu diputuskan lagi.')->action('Buka persetujuan', $this->url($notifiable));
        }

        if ($this->audience === 'guru') {
            $mail->action('Lihat pendaftaran', $this->url($notifiable));
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.cancelled',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->audience === 'siswa' ? 'Pendaftaran dibatalkan' : $this->subject(),
            'message' => $this->message(),
            'url' => $this->url($notifiable),
        ];
    }

    private function subject(): string
    {
        return match ($this->audience) {
            'guru' => 'Pendaftaran binaan dibatalkan',
            'kepsek' => 'Pendaftaran lomba dibatalkan',
            default => 'Pendaftaran dibatalkan: '.$this->competitionName(),
        };
    }

    private function message(): string
    {
        $leader = $this->leaderName();
        $lomba = $this->competitionName();

        return match ($this->audience) {
            'guru' => $leader.' membatalkan pendaftaran binaan di '.$lomba.'.',
            'kepsek' => $leader.' membatalkan pendaftaran '.$lomba.' ('.($this->registration->isTeam() ? 'tim' : 'individu').').',
            default => $leader.' membatalkan pendaftaran '.$lomba.'.',
        };
    }

    private function url(object $notifiable): string
    {
        return match ($this->audience) {
            'guru' => self::link([['guru.binaan.show', ['registration' => $this->registration->getKey()]], 'guru.beranda']),
            // D-150: each approver's own Persetujuan address (Kepala Sekolah or Tata Usaha).
            'kepsek' => self::approvalLink($notifiable),
            default => self::link(['siswa.lomba-saya.index', 'siswa.beranda']),
        };
    }
}
