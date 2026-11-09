<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Models\Registration;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: to a student a revision invited, when that revision ends without bringing the student into the team: the
 * ketua cancelled it (CANCELLED), another invited student declined (DECLINED), or Tata Usaha or Kesiswaan rejected it
 * (REJECTED; the decider's note is for the ketua only).
 */
class RevisionWithdrawn extends RegistrationNotification
{
    public const CANCELLED = 'cancelled';

    public const DECLINED = 'declined';

    public const REJECTED = 'rejected';

    public function __construct(Registration $registration, public string $why = self::CANCELLED)
    {
        parent::__construct($registration);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new PlainMailMessage)
            ->subject(($this->why === self::REJECTED ? 'Revisi tim tidak disetujui: ' : 'Undangan tim dibatalkan: ').$this->competitionName())
            ->greeting(MailGreeting::student($notifiable))
            ->line($this->message())
            ->line('Kamu tidak perlu menjawab undangan itu lagi.')
            ->action('Lihat lomba', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.revision_withdrawn',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->why === self::REJECTED ? 'Revisi tim tidak disetujui' : 'Undangan tim dibatalkan',
            'message' => $this->message(),
            'url' => $this->url(),
        ];
    }

    private function message(): string
    {
        $lomba = $this->competitionName();

        return match ($this->why) {
            self::DECLINED => 'Revisi tim '.$lomba.' batal karena ada siswa lain yang menolak undangannya, jadi undanganmu tidak berlaku lagi.',
            self::REJECTED => 'Revisi tim '.$lomba.' tidak disetujui sekolah, jadi kamu tidak masuk tim itu.',
            default => $this->leaderName().' membatalkan revisi tim '.$lomba.', jadi undanganmu tidak berlaku lagi.',
        };
    }

    private function url(): string
    {
        return self::link([['siswa.lomba.show', ['competition' => $this->registration->competition_id]], 'siswa.lomba.index']);
    }
}
