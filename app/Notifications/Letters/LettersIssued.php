<?php

declare(strict_types=1);

namespace App\Notifications\Letters;

use App\Enums\LetterType;
use App\Models\Letter;
use App\Models\Registration;
use App\Notifications\Registrations\RegistrationNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Route;

/**
 * Surat tugas sent (disetujui → surat_terbit). Audience `siswa`: the shared team letter; `guru`: the
 * Surat Perintah Tugas (the mentor may also open the team letter). Links go through the policy-checked
 * file controller (`files.show`, login required); the mail itself carries no attachment and no NIS.
 */
class LettersIssued extends RegistrationNotification
{
    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience): self
    {
        return new self($registration, $audience === 'guru' ? 'guru' : 'siswa');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? 'Halo '.self::firstName($notifiable).',' : 'Yth. '.trim((string) ($notifiable->name ?? '')).',')
            ->line($this->message());

        if ($this->audience === 'siswa') {
            $mail->line('Bawa surat ini saat lomba. Keasliannya bisa dicek lewat kode QR di surat.');
        }

        return $mail->action('Unduh surat', $this->url())->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'letters.issued',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function subject(): string
    {
        return $this->audience === 'guru' ? 'Surat perintah tugas terbit' : 'Surat tugas kamu sudah terbit';
    }

    private function message(): string
    {
        $number = $this->letter()?->full_number;
        $lomba = $this->competitionName();

        return $this->audience === 'guru'
            ? 'Surat Perintah Tugas pendampingan '.$lomba.($number ? ' (Nomor '.$number.')' : '').' sudah terbit.'
            : 'Surat tugas untuk '.$lomba.($number ? ' (Nomor '.$number.')' : '').' sudah terbit.';
    }

    private function letter(): ?Letter
    {
        $type = $this->audience === 'guru' ? LetterType::Guru : LetterType::Siswa;

        return $this->registration->letters()->where('type', $type)->first()
            ?? $this->registration->letters()->where('type', LetterType::Siswa)->first();
    }

    private function url(): string
    {
        $letter = $this->letter();

        if ($letter?->file_id !== null && Route::has('files.show')) {
            return route('files.show', ['file' => $letter->file_id]);
        }

        return $this->audience === 'guru'
            ? self::link(['guru.beranda'])
            : self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index']);
    }
}
