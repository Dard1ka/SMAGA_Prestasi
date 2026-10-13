<?php

declare(strict_types=1);

namespace App\Notifications\Approval;

use App\Actions\Registrations\MentorChoice;
use App\Models\Registration;
use App\Models\User;
use App\Notifications\Registrations\RegistrationNotification;
use App\Notifications\Support\MailGreeting;
use App\Notifications\Support\PlainMailMessage;
use App\Support\StaffRoleLabel;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * An approver (Kepala Sekolah or Tata Usaha, D-150) approved a registration (ARCHITECTURE §6: email to students,
 * mentor, TU). One class, three audiences: `siswa` (team members), `guru` (pembimbing), `tu` (letter queue).
 * Minimal content: lomba name, leader name, a link. Never NIS/NISN/contact data (SECURITY §3m-3).
 *
 * D-132 (lead, 2026-10-11): the students always read who their guru pembimbing is, and when the approver put
 * another guru in the place of their choice the notice says so and names both. The guru of the registration is then
 * the appointed one: that notice reads as an appointment, not as news about a binaan they already had. The names are
 * kept as they were at the decision (plain properties with defaults, so a notice queued before this change still
 * unserializes).
 *
 * D-150: the notices name the role that decided (StaffRoleLabel::forDecision()); the students and Tata Usaha also
 * read the approver's name. A notice without a decider (queued before D-150) speaks of "sekolah".
 */
class RegistrationApproved extends RegistrationNotification
{
    public const AUDIENCES = ['siswa', 'guru', 'tu'];

    /** The guru pembimbing after the decision ("Bu Retno Wulandari, S.Pd."), null when the registration has none. */
    public ?string $mentorName = null;

    /** True when the approver replaced the student's choice with this approval. */
    public bool $mentorChanged = false;

    /** The guru who was replaced, when there was one. */
    public ?string $replacedMentorName = null;

    /** D-150: the name of the account that decided, null when unknown. */
    public ?string $deciderName = null;

    /** D-150: the role that decided ("Kepala Sekolah", "Tata Usaha"), null when unknown. */
    public ?string $deciderRole = null;

    public function __construct(Registration $registration, public string $audience = 'siswa')
    {
        parent::__construct($registration);
    }

    public static function for(
        Registration $registration,
        string $audience,
        bool $mentorChanged = false,
        ?string $replacedMentorName = null,
        ?User $decider = null,
    ): self {
        $notification = new self($registration, in_array($audience, self::AUDIENCES, true) ? $audience : 'siswa');

        if ($decider !== null) {
            $notification->deciderName = (string) $decider->name;
            $notification->deciderRole = StaffRoleLabel::forDecision($decider);
        }

        // Tata Usaha reads the guru on the letter screen: its notice stays without the guru.
        if ($notification->audience !== 'tu') {
            $notification->mentorName = MentorChoice::name($registration->mentor_id !== null
                ? User::query()->with('staffProfile')->find($registration->mentor_id)
                : null);
            $notification->mentorChanged = $mentorChanged && $notification->mentorName !== null;
            $notification->replacedMentorName = $notification->mentorChanged ? $replacedMentorName : null;
        }

        return $notification;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new PlainMailMessage)
            ->subject($this->subject())
            ->greeting($this->audience === 'siswa' ? MailGreeting::student($notifiable) : MailGreeting::staff($notifiable))
            ->line($this->message());

        if ($this->audience === 'siswa') {
            $mentor = $this->mentorLine();

            if ($mentor !== null) {
                $mail->line($mentor);
            }

            $mail->line('Tata Usaha sedang menyiapkan surat tugas. Kamu akan diberi tahu saat surat siap.');
        }

        return $mail->action($this->actionLabel(), $this->url());
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $mentor = $this->audience === 'siswa' ? $this->mentorLine() : null;

        return [
            'type' => 'registration.approved',
            'registration_id' => $this->registration->getKey(),
            'title' => $this->subject(),
            'message' => $this->message().($mentor !== null ? ' '.$mentor : ''),
            'url' => $this->url(),
        ];
    }

    /**
     * The sentence that names the students' guru pembimbing, or null when the registration has none (rows from before
     * the guru became required).
     */
    public function mentorLine(): ?string
    {
        if ($this->mentorName === null) {
            return null;
        }

        if (! $this->mentorChanged) {
            return 'Guru pembimbing: '.MentorChoice::closing($this->mentorName);
        }

        return 'Guru pembimbing: '.$this->mentorName.' (ditetapkan '.$this->role()
            .($this->replacedMentorName !== null ? ', menggantikan '.$this->replacedMentorName : '').').';
    }

    /** The role that decided, "sekolah" when unknown (D-150). */
    private function role(): string
    {
        return $this->deciderRole ?? StaffRoleLabel::FALLBACK;
    }

    private function hasDeciderName(): bool
    {
        return $this->deciderName !== null && $this->deciderName !== '';
    }

    private function subject(): string
    {
        return match ($this->audience) {
            'tu' => 'Surat tugas perlu dibuat',
            'guru' => $this->mentorChanged ? 'Ditetapkan sebagai guru pembimbing' : 'Pendaftaran binaan disetujui',
            default => 'Pendaftaran lomba disetujui',
        };
    }

    private function message(): string
    {
        $lomba = $this->competitionName();
        $role = $this->role();

        return match ($this->audience) {
            'tu' => ($this->hasDeciderName() ? $this->deciderName.' ('.$role.')' : ucfirst($role))
                .' menyetujui pendaftaran '.$lomba.' ('.$this->leaderName().'). Isi nomor surat lalu buat PDF-nya.',
            'guru' => $this->mentorChanged
                // The reader is the guru named after the colon: an appointment, told without a pronoun (D-119).
                ? MentorChoice::closing(ucfirst($role).' menyetujui pendaftaran '.$lomba.' ('.$this->leaderName().') dan menetapkan guru pembimbingnya: '
                    .$this->mentorName.($this->replacedMentorName !== null ? ', menggantikan '.$this->replacedMentorName : ''))
                : 'Pendaftaran binaan di '.$lomba.' ('.$this->leaderName().') disetujui '.$role.'.',
            default => $this->hasDeciderName()
                ? 'Pendaftaranmu di '.$lomba.' disetujui oleh '.$this->deciderName.' ('.$role.').'
                : 'Pendaftaranmu di '.$lomba.' disetujui '.$role.'.',
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
            'guru' => self::link([['guru.binaan.show', ['registration' => $this->registration->getKey()]], 'guru.beranda']),
            default => self::link([['siswa.lomba-saya.show', ['registration' => $this->registration->getKey()]], 'siswa.lomba-saya.index', 'siswa.beranda']),
        };
    }
}
