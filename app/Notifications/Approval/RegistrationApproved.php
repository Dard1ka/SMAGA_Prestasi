<?php

declare(strict_types=1);

namespace App\Notifications\Approval;

use App\Models\Registration;
use App\Notifications\Registrations\RegistrationNotification;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Kepala Sekolah approved a registration (ARCHITECTURE §6: email to students, mentor, TU).
 * One class, three audiences: `siswa` (team members), `guru` (pembimbing), `tu` (letter queue).
 * Minimal content: lomba name, leader name, a link. Never NIS/NISN/contact data (SECURITY §3m-3).
 */
class RegistrationApproved extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru', 'tu'];

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
            ->greeting($this->audience === 'siswa' ? 'Halo '.self::firstName($notifiable).',' : 'Yth. '.trim((string) ($notifiable->name ?? '')).',')
            ->line($this->message());

        if ($this->audience === 'siswa') {
            $mail->line('Tata Usaha sedang menyiapkan surat tugas. Kamu akan diberi tahu saat surat siap.');
        }

        return $mail->action($this->actionLabel(), $this->url())->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.approved',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function subject(): string
    {
        return match ($this->audience) {
            'tu' => 'Surat tugas perlu dibuat',
            'guru' => 'Pendaftaran binaan disetujui',
            default => 'Pendaftaran lomba disetujui',
        };
    }

    private function message(): string
    {
        $lomba = $this->competitionName();

        return match ($this->audience) {
            'tu' => 'Kepala Sekolah menyetujui pendaftaran '.$lomba.' ('.$this->leaderName().'). Isi nomor surat lalu buat PDF-nya.',
            'guru' => 'Pendaftaran binaan di '.$lomba.' ('.$this->leaderName().') disetujui Kepala Sekolah.',
            default => 'Kepala Sekolah menyetujui pendaftaranmu di '.$lomba.'.',
        };
    }

    private function actionLabel(): string
    {
        return $this->audience === 'tu' ? 'Buka antrean surat' : 'Lihat pendaftaran';
    }

    private function url(): string
    {
        return match ($this->audience) {
            'tu' => self::link([['tu.surat.show', ['registration' => $this->registration->getKey()]], 'tu.beranda']),
            'guru' => self::link(['guru.beranda']),
            default => self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']),
        };
    }
}
