<?php

declare(strict_types=1);

namespace App\Notifications\Registrations;

use App\Actions\Registrations\MentorChoice;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Support\StaffRoleLabel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * SV8, D-199: Tata Usaha or Kesiswaan decided a revision (ApproveRevision / RejectRevision). Audiences:
 * - `ketua`: approved or rejected; a rejection carries the decider's note;
 * - `anggota`: a member who stays in the team, after an approval;
 * - `baru`: a student the approved revision brought into the team;
 * - `guru`: the guru pembimbing after an approval: the new one as an appointment, else the guru who stays (the guru who
 *   was replaced is told nothing, D-134);
 * - `tu`: Tata Usaha, when the letters of the registration were issued already: the letters are not changed by the
 *   revision, so Tata Usaha makes a Koreksi.
 * Students read "kamu"; staff no pronoun (D-119). Minimal content (SECURITY §3m-3).
 */
class RevisionDecided extends RegistrationNotification
{
    public const AUDIENCES = ['ketua', 'anggota', 'baru', 'guru', 'tu'];

    /** The role that decided ("Tata Usaha", "Kesiswaan"), kept as it was at the decision. */
    public string $deciderRole = StaffRoleLabel::FALLBACK;

    /** The guru pembimbing after the decision (for the `guru` audience). */
    public ?string $mentorName = null;

    public function __construct(
        Registration $registration,
        public string $audience = 'ketua',
        public bool $approved = true,
        public ?string $note = null,
        public bool $mentorChanged = false,
    ) {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience, bool $approved, ?User $decider, ?string $note = null, bool $mentorChanged = false): self
    {
        $notification = new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'ketua', $approved, $note, $mentorChanged);
        $notification->deciderRole = StaffRoleLabel::forDecision($decider);
        $notification->mentorName = MentorChoice::name($registration->mentor_id !== null
            ? User::query()->with('staffProfile')->find($registration->mentor_id)
            : null);

        return $notification;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting(in_array($this->audience, ['guru', 'tu'], true) ? MailGreeting::staff($notifiable) : MailGreeting::student($notifiable))
            ->line($this->message());

        if ($this->noteLine() !== null) {
            $mail->line($this->noteLine());
        }

        return $mail->action($this->audience === 'tu' ? 'Buka surat tugas' : 'Lihat pendaftaran', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.revision_decided',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => trim($this->message().' '.($this->noteLine() ?? '')),
            'url' => $this->url(),
        ];
    }

    private function subject(): string
    {
        $lomba = $this->competitionName();

        return match ($this->audience) {
            'ketua' => ($this->approved ? 'Revisi disetujui: ' : 'Revisi ditolak: ').$lomba,
            'anggota' => 'Tim diubah: '.$lomba,
            'baru' => 'Kamu masuk tim: '.$lomba,
            'guru' => $this->mentorChanged ? 'Ditetapkan sebagai guru pembimbing' : 'Tim binaan diubah: '.$lomba,
            default => 'Surat tugas perlu dikoreksi: '.$lomba,
        };
    }

    private function message(): string
    {
        $lomba = $this->competitionName();
        $role = $this->deciderRole;

        return match ($this->audience) {
            'ketua' => $this->approved
                ? 'Revisi pendaftaranmu di '.$lomba.' disetujui '.$role.'. Tim dan guru pembimbing sudah diperbarui.'
                : 'Revisi pendaftaranmu di '.$lomba.' ditolak '.$role.'. Tim yang sudah disetujui tetap berlaku.',
            'anggota' => 'Tim '.$lomba.' diubah lewat revisi yang disetujui '.$role.'. Lihat susunan tim dan guru pembimbingnya di Lomba saya.',
            'baru' => 'Revisi tim '.$lomba.' disetujui '.$role.'. Kamu sekarang anggota tim ini.',
            'guru' => $this->mentorChanged
                ? MentorChoice::closing('Ditetapkan sebagai guru pembimbing pendaftaran '.$lomba.' ('.$this->leaderName().') lewat revisi yang disetujui '.$role.': '.($this->mentorName ?? 'guru pembimbing baru'))
                : 'Tim pendaftaran '.$lomba.' ('.$this->leaderName().') diubah lewat revisi yang disetujui '.$role.'.',
            default => 'Revisi pendaftaran '.$lomba.' ('.$this->leaderName().') disetujui '.$role
                .'. Surat tugasnya sudah terbit dan belum memuat perubahan tim atau guru pembimbing ini, jadi perlu dibuat Koreksi.',
        };
    }

    private function noteLine(): ?string
    {
        $note = trim((string) $this->note);

        return $this->audience === 'ketua' && ! $this->approved && $note !== '' ? 'Alasan: '.$note : null;
    }

    private function url(): string
    {
        $id = $this->registration->getKey();

        return match ($this->audience) {
            'guru' => self::link([['guru.binaan.show', ['registration' => $id]], 'guru.beranda']),
            'tu' => self::link([['tu.surat.show', ['registration' => $id]], 'tu.beranda']),
            default => self::link([['siswa.lomba-saya.show', ['registration' => $id]], 'siswa.lomba-saya.index']),
        };
    }
}
