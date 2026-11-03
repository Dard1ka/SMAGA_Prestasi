<?php

declare(strict_types=1);

namespace App\Notifications\Letters;

use App\Enums\LetterType;
use App\Models\Letter;
use App\Models\Registration;
use App\Notifications\Registrations\RegistrationNotification;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Surat tugas sent (disetujui → surat_terbit). Audience `siswa`: the shared team letter; `guru`: the
 * Surat Perintah Tugas (the mentor may also open the team letter). The mail itself carries no attachment and no NIS.
 * QA10: the link opens the screen that lists the CURRENT letters (Siswa › Lomba saya, Guru › detail binaan), not one
 * file version: after TU's "Koreksi" the old file answers 404 and the mail would have pointed at it.
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
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? MailGreeting::student($notifiable) : MailGreeting::staff($notifiable))
            ->line($this->message());

        if ($this->audience === 'siswa') {
            $mail->line('Bawa surat ini saat lomba. Keasliannya bisa dicek lewat kode QR di surat.');
        }

        return $mail->action('Lihat surat', $this->url());
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
        return self::lettersScreen($this->registration, $this->audience);
    }

    /**
     * The screen that lists the current letters of a registration (QA10), shared with LetterRevised: Lomba saya for
     * the members (download of the siswa letter), the binaan detail for the guru pembimbing (preview popup, D-112).
     */
    public static function lettersScreen(Registration $registration, string $audience): string
    {
        $registrationId = $registration->getKey();

        return $audience === 'guru'
            ? self::link([['guru.binaan.show', ['registration' => $registrationId]], 'guru.beranda'])
            : self::link([['siswa.lomba-saya.show', ['registration' => $registrationId]], 'siswa.lomba-saya.index']);
    }
}
