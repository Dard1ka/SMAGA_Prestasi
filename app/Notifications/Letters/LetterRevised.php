<?php

declare(strict_types=1);

namespace App\Notifications\Letters;

use App\Models\Registration;
use App\Notifications\Registrations\RegistrationNotification;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A sent surat tugas was revoked and replaced by a new version (B3, backlog #11). Audience `siswa`
 * (team members, shared letter) or `guru` (the mentor's Surat Perintah Tugas). Only numbers and the
 * lomba name. The TU's reason stays in the audit log. QA10: the link opens the screen with the current letters
 * (LettersIssued::lettersScreen()), not the file of this version: a later "Koreksi" would make that file a 404.
 * `$fileId` is kept for the callers and the queued payload; the link no longer uses it.
 */
class LetterRevised extends RegistrationNotification
{
    public function __construct(
        Registration $registration,
        public string $audience,
        public string $oldNumber,
        public string $newNumber,
        public ?string $fileId,
    ) {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? 'Halo '.self::firstName($notifiable).',' : 'Yth. '.trim((string) ($notifiable->name ?? '')).',')
            ->line($this->message())
            ->line('Pakai surat yang baru. Keasliannya bisa dicek lewat kode QR di surat.')
            ->action('Lihat surat baru', $this->url())
            ->salutation('SMAGA Talent Hub');
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'letter.revised',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    public function message(): string
    {
        $what = $this->audience === 'guru' ? 'Surat Perintah Tugas pendampingan ' : 'Surat tugas ';

        return $what.$this->competitionName().' nomor '.$this->oldNumber.' dicabut dan diganti surat nomor '.$this->newNumber
            .'. Surat lama tidak berlaku lagi.';
    }

    private function subject(): string
    {
        return $this->audience === 'guru' ? 'Surat perintah tugas diperbarui' : 'Surat tugas kamu diperbarui';
    }

    private function url(): string
    {
        return LettersIssued::lettersScreen($this->registration, $this->audience);
    }
}
