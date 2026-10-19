<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Models\User;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Support\StaffRoleLabel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * D-155: Tata Usaha removed an approved registration from the letter queue (CancelApprovedRegistration). Three
 * audiences: `siswa` (the team members), `guru` (pembimbing, opens the registration in the guru area) and `pemutus`
 * (the approver who approved it, when that is somebody else). The sentence names who cancelled and in which role
 * (StaffRoleLabel, D-150) and carries the reason as written. Names, the lomba and the reason only (§3m-3).
 */
class RegistrationCancelledBySchool extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru', 'pemutus'];

    public string $deciderName = '';

    public string $deciderRole = StaffRoleLabel::FALLBACK;

    public string $note = '';

    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience, User $decider, string $note): self
    {
        $notification = new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'siswa');
        $notification->deciderName = (string) $decider->name;
        $notification->deciderRole = StaffRoleLabel::forDecision($decider);
        $notification->note = $note;

        return $notification;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? MailGreeting::student($notifiable) : MailGreeting::staff($notifiable))
            ->line($this->message())
            ->line('Alasan: '.$this->note);

        if ($this->audience === 'siswa') {
            $mail->line('Kamu bisa mendaftar lagi selama pendaftaran lomba masih dibuka.');
        }

        return $mail->action($this->audience === 'pemutus' ? 'Buka beranda' : 'Lihat pendaftaran', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.cancelled_by_school',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->audience === 'siswa' ? 'Pendaftaran dibatalkan' : $this->subject(),
            'message' => $this->message().' Alasan: '.$this->note,
            'url' => $this->url(),
        ];
    }

    private function subject(): string
    {
        return match ($this->audience) {
            'guru' => 'Pendaftaran binaan dibatalkan',
            'pemutus' => 'Pendaftaran yang disetujui dibatalkan',
            default => 'Pendaftaran dibatalkan: '.$this->competitionName(),
        };
    }

    private function message(): string
    {
        $by = $this->deciderName !== '' ? $this->deciderName.' ('.$this->deciderRole.')' : $this->deciderRole;
        $lomba = $this->competitionName();

        return match ($this->audience) {
            'guru' => 'Pendaftaran binaan di '.$lomba.' ('.$this->leaderName().') dibatalkan oleh '.$by.' sebelum surat tugas dikirim.',
            'pemutus' => 'Pendaftaran '.$lomba.' ('.$this->leaderName().') yang sudah disetujui dibatalkan oleh '.$by.' sebelum surat tugas dikirim.',
            default => 'Pendaftaranmu di '.$lomba.' dibatalkan oleh '.$by.' sebelum surat tugas dikirim.',
        };
    }

    private function url(): string
    {
        return match ($this->audience) {
            'guru' => self::link([['guru.binaan.show', ['registration' => $this->registration->getKey()]], 'guru.beranda']),
            'pemutus' => url('/beranda'),
            default => self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']),
        };
    }
}
