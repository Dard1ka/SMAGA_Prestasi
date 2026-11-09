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
 * SV8, D-198: Kesiswaan (or Admin) set or replaced the guru pembimbing of a registration on Kelola lomba
 * (App\Actions\Registrations\ChangeMentor). Audiences, the rules of the approval's mentor change (D-132, D-134):
 * `siswa` = the confirmed members, who read who their guru is now ("Guru pembimbing: … (ditetapkan Kesiswaan,
 * menggantikan …)."), `guru` = the new guru, told as an appointment, `tu` = Tata Usaha when the GURU letter of the
 * registration was issued already (fixed 2026-11-09): it keeps the guru it printed (D-108), so Tata Usaha makes a Koreksi.
 * A guru letter needs a guru, so `tu` is always about a replaced guru ("diganti"); the student letter prints no guru.
 * The guru who was replaced gets nothing (only the name, kept as it was at the change). Staff read no pronoun (D-119).
 * Minimal content: lomba, ketua, names of the gurus (never a NIP), a link (SECURITY §3m-3).
 */
class MentorAssigned extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru', 'tu'];

    /** The guru pembimbing after the change ("Pak Hendra Kusuma, S.Pd., M.Si."). */
    public ?string $mentorName = null;

    /** The guru who was replaced, null when the registration had none. */
    public ?string $replacedMentorName = null;

    /** The role that set the guru ("Kesiswaan", "Admin"), null when unknown ("sekolah"). */
    public ?string $deciderRole = null;

    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(Registration $registration, string $audience, ?string $replacedMentorName = null, ?User $decider = null): self
    {
        $notification = new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'siswa');
        $notification->mentorName = MentorChoice::name($registration->mentor_id !== null
            ? User::query()->with('staffProfile')->find($registration->mentor_id)
            : null);
        $notification->replacedMentorName = $replacedMentorName;
        $notification->deciderRole = $decider !== null ? StaffRoleLabel::forDecision($decider) : null;

        return $notification;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? MailGreeting::student($notifiable) : MailGreeting::staff($notifiable))
            ->line($this->message());

        if ($this->audience === 'siswa') {
            $mail->line($this->mentorLine());
        }

        return $mail->action($this->audience === 'tu' ? 'Buka surat tugas' : 'Lihat pendaftaran', $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'registration.mentor_changed',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => $this->message().($this->audience === 'siswa' ? ' '.$this->mentorLine() : ''),
            'url' => $this->url(),
        ];
    }

    /** The students' line, as in the approval notice (RegistrationApproved::mentorLine()). */
    public function mentorLine(): string
    {
        return 'Guru pembimbing: '.$this->mentor().' (ditetapkan '.$this->role()
            .($this->replacedMentorName !== null ? ', menggantikan '.$this->replacedMentorName : '').').';
    }

    private function mentor(): string
    {
        return $this->mentorName ?? 'guru pembimbing baru';
    }

    private function role(): string
    {
        return $this->deciderRole ?? StaffRoleLabel::FALLBACK;
    }

    private function subject(): string
    {
        return match ($this->audience) {
            'guru' => 'Ditetapkan sebagai guru pembimbing',
            'tu' => 'Guru pembimbing diganti, surat perlu Koreksi',
            default => $this->replacedMentorName !== null ? 'Guru pembimbing diganti' : 'Guru pembimbing ditetapkan',
        };
    }

    private function message(): string
    {
        $lomba = $this->competitionName();

        return match ($this->audience) {
            // The reader is the guru named after the colon: an appointment, told without a pronoun (D-119).
            'guru' => MentorChoice::closing(ucfirst($this->role()).' menetapkan guru pembimbing pendaftaran '.$lomba.' ('.$this->leaderName().'): '
                .$this->mentor().($this->replacedMentorName !== null ? ', menggantikan '.$this->replacedMentorName : '')),
            // Tata Usaha, without a pronoun (D-119): the issued guru letter still prints the guru of its day. A guru
            // letter needs a guru, so there always was one ("guru pembimbing sebelumnya" only if the name is unknown).
            'tu' => MentorChoice::closing(ucfirst($this->role()).' mengganti guru pembimbing pendaftaran '.$lomba.' ('.$this->leaderName().'): '
                .$this->mentor().($this->replacedMentorName !== null ? ', menggantikan '.$this->replacedMentorName : ''))
                .' Surat tugas guru yang sudah terbit masih memuat '.($this->replacedMentorName ?? 'guru pembimbing sebelumnya')
                .', jadi Tata Usaha perlu membuat Koreksi di Arsip surat.',
            default => $this->replacedMentorName !== null
                ? 'Guru pembimbing pendaftaranmu di '.$lomba.' diganti.'
                : 'Guru pembimbing pendaftaranmu di '.$lomba.' sudah ditetapkan.',
        };
    }

    private function url(): string
    {
        return match ($this->audience) {
            'guru' => self::link([['guru.binaan.show', ['registration' => $this->registration->getKey()]], 'guru.beranda']),
            // The letter screen of the registration, as RevisionDecided `tu`: Koreksi starts there.
            'tu' => self::link([['tu.surat.show', ['registration' => $this->registration->getKey()]], 'tu.beranda']),
            default => self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']),
        };
    }
}
