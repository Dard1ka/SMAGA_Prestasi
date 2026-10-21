<?php

declare(strict_types=1);

namespace App\Livewire\Siswa;

use App\Actions\Accounts\UpdateStudentPhone;
use App\Actions\Registrations\ConfirmMembership;
use App\Actions\Registrations\DeclineMembership;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Domain\Accounts\PhoneNumber;
use App\Enums\RegistrationStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Policies\RegistrationPolicy;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Siswa · Konfirmasi ikut tim (link from the TeamInvitation notification and Beranda "Perlu tindakan").
 * Only members of the registration may open it (RegistrationPolicy::view, else 404); only the invitee
 * may answer (RegistrationPolicy::respond → ConfirmMembership / DeclineMembership, SECURITY §3m-3).
 * QA9: once the ketua withdrew the invitation (WithdrawInvitation deletes the member row) the page says so, without
 * any team data, instead of a 404 on "Ikut tim", "Tolak", the next render or the old link.
 * QA10: the answer works until the registration deadline, also on a lomba Kesiswaan closed early; an invitation the
 * scheduler expired reads "tidak berlaku karena batas pendaftaran lewat" / "Tidak menjawab", never as a decline; the
 * ketua is said to be told only when DeclineMembership told the ketua.
 */
#[Layout('layouts::app')]
#[Title('Undangan tim')]
class KonfirmasiTim extends Component
{
    public const WITHDRAWN = 'Undangan ini sudah dibatalkan ketua tim.';

    #[Locked]
    public string $registrationId = '';

    /** The ketua withdrew this student's invitation (QA9): nothing of the team is shown any more. */
    #[Locked]
    public bool $withdrawn = false;

    /** D-149: the anggota's own No HP, pre-filled from the account; ikut() saves a changed one (UpdateStudentPhone). */
    public string $phone = '';

    public function mount(Registration $registration): void
    {
        if (! $this->isMember($registration)) {
            // The link of the invitation still reaches the invitee whose invitation was withdrawn; anyone else gets 404.
            abort_unless($this->wasWithdrawn($registration), 404);
            $this->withdrawn = true;
        }

        $this->registrationId = $registration->getKey();
        $this->phone = (string) rescue(fn () => auth()->user()->loadMissing('student')->student?->phone, null, false);
    }

    public function ikut(ConfirmMembership $confirm, UpdateStudentPhone $phones): void
    {
        $registration = $this->registration();

        if ($registration === null) {
            $this->dispatch('sth-toast', message: self::WITHDRAWN, tone: 'warning');

            return;
        }

        if (Gate::denies('respond', $registration)) {
            $this->addError('registration', 'Undangan ini sudah tidak berlaku.');

            return;
        }

        $this->phone = PhoneNumber::normalize($this->phone);
        $this->validate(
            ['phone' => ['required', 'regex:'.PhoneNumber::PATTERN]],
            ['phone.required' => UpdateStudentPhone::EMPTY, 'phone.regex' => PhoneNumber::INVALID],
        );

        // The own number first (D-149); a refusal of the confirmation below still leaves it saved: it is the student's own.
        $phones->handle(auth()->user(), auth()->user()->loadMissing('student')->student, $this->phone);
        $confirm->handle(auth()->user(), $registration);

        session()->flash('toast', 'Kamu ikut tim '.$registration->competition->name.'.');
        $this->redirectRoute('siswa.undangan.show', ['registration' => $registration->getKey()]);
    }

    public function tolak(DeclineMembership $decline): void
    {
        $registration = $this->registration();

        if ($registration === null) {
            $this->dispatch('sth-toast', message: self::WITHDRAWN, tone: 'warning');

            return;
        }

        if (Gate::denies('respond', $registration)) {
            $this->addError('registration', 'Undangan ini sudah tidak berlaku.');

            return;
        }

        $decline->handle(auth()->user(), $registration);

        // QA10 (D-109): "diberi tahu" only when DeclineMembership told the ketua (an account that can log in).
        session()->flash('toast', ['tone' => 'info', 'message' => 'Undangan ditolak.'
            .(ConfirmMembership::notifiesLeader($registration) ? ' Ketua tim sudah diberi tahu.' : '')]);
        $this->redirectRoute('siswa.beranda');
    }

    public function render(): View
    {
        $registration = $this->registration();

        if ($registration === null) {
            return view('livewire.siswa.konfirmasi-tim', ['withdrawn' => true]);
        }

        $me = auth()->user()->loadMissing('student.classroom')->getRelation('student');
        $mine = $registration->members->firstWhere('student_id', $me?->getKey());
        $competition = $registration->competition;
        $mentor = $registration->mentor;
        $canRespond = Gate::allows('respond', $registration);
        // QA10: the scheduler declines unanswered invitations at the deadline (D-50); that is not the student's answer.
        $expiredIds = ExpirePendingInvitations::expiredMemberIds($registration);
        $unanswered = $mine !== null && $mine->confirmed_at === null && $mine->declined_at === null;

        return view('livewire.siswa.konfirmasi-tim', [
            'withdrawn' => false,
            'registration' => $registration,
            'competition' => $competition,
            'canRespond' => $canRespond,
            'mine' => $mine,
            // Expired by the job, or still unanswered after the deadline (before the job ran).
            'expired' => $mine !== null && ! $canRespond && (in_array((string) $mine->getKey(), $expiredIds, true)
                || ($unanswered && $registration->status === RegistrationStatus::MenungguKonfirmasi
                    && ! RegistrationPolicy::teamWindowOpen($registration))),
            // D-148: a team resumed late had its own three days to answer, not the registration deadline.
            'expiredBy' => $registration->answer_until !== null ? 'batas jawab' : 'batas pendaftaran',
            'notifiesLeader' => ConfirmMembership::notifiesLeader($registration),
            // D-149 (FS1 QA round 2, BUG-07): before "Ikut tim" the invitee checks the own data, read-only, like the ketua
            // in the Pendaftaran lomba panel. Only the viewer's own account: never another member's birth date or NISN.
            'peserta' => PendaftaranForm::pesertaRows(auth()->user(), $me),
            // FS1: the one order of every team list (RegistrationMember::inTeamOrder()).
            'members' => RegistrationMember::inTeamOrder($registration->members)
                ->map(fn (RegistrationMember $m): array => [
                    'id' => $m->getKey(),
                    'name' => (string) $m->student?->user?->name,
                    'kelas' => (string) $m->student?->classroom?->name,
                    'role' => $m->role?->label(),
                    'me' => $m->getKey() === $mine?->getKey(),
                    'state' => match (true) {
                        in_array((string) $m->getKey(), $expiredIds, true) => ['Tidak menjawab', 'gray'],
                        $m->declined_at !== null => ['Menolak', 'red'],
                        $m->confirmed_at !== null => ['Sudah konfirmasi', 'green'],
                        default => ['Menunggu konfirmasi', 'gray'],
                    },
                ])->values()->all(),
            'mentorName' => $mentor === null ? '–' : trim(($mentor->staffProfile?->honorific ? $mentor->staffProfile->honorific.' ' : '').$mentor->name),
            'facts' => [
                // D-147: the same three periods as Detail lomba (no report deadline, D-151).
                ['icon' => 'clock', 'label' => 'Pendaftaran', 'value' => LombaPresenter::pendaftaran($competition) ?? 'Menyusul'],
                // D-148: a team resumed after (or shortly before) the deadline answers until its own moment.
                ...($canRespond && ($grace = $registration->graceText()) !== null
                    ? [['icon' => 'clock', 'label' => 'Batas jawab', 'value' => $grace]]
                    : []),
                ['icon' => 'calendar', 'label' => 'Pelaksanaan', 'value' => LombaPresenter::tanggal($competition)],
                ['icon' => 'upload', 'label' => 'Laporan hasil', 'value' => LombaPresenter::REPORT_LINE],
                ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => LombaPresenter::tingkat($competition)],
                ['icon' => 'pin', 'label' => 'Tempat', 'value' => LombaPresenter::tempat($competition)],
            ],
            'detailUrl' => Gate::allows('view', $competition) ? route('siswa.lomba.show', $competition) : null,
            'lombaSayaUrl' => Route::has('siswa.lomba-saya.show') ? route('siswa.lomba-saya.show', $registration) : null,
        ]);
    }

    /** The registration, or null once the invitation of this student was withdrawn (QA9). */
    private function registration(): ?Registration
    {
        if ($this->withdrawn) {
            return null;
        }

        $registration = Registration::query()
            ->with(['competition', 'mentor.staffProfile', 'members.student.user:id,name', 'members.student.classroom:id,name'])
            ->findOrFail($this->registrationId);

        if ($this->isMember($registration)) {
            return $registration;
        }

        // `registrationId` is #[Locked] and set only for a member (or a withdrawn invitee) in mount(); only
        // WithdrawInvitation deletes a member row, so the membership that is gone now was a withdrawn invitation.
        $this->withdrawn = true;

        return null;
    }

    /** WithdrawInvitation audited the withdrawal of this student's invitation to this registration. */
    private function wasWithdrawn(Registration $registration): bool
    {
        $student = auth()->user()?->loadMissing('student')->getRelation('student');

        return $student !== null && Activity::query()
            ->where('event', 'registration.invitation_withdrawn')
            ->where('subject_type', $registration->getMorphClass())
            ->where('subject_id', $registration->getKey())
            ->where('properties->student_id', $student->getKey())
            ->exists();
    }

    /**
     * Students only reach this page for registrations they are part of (staff use their own screens). Not before the
     * invitation went out: an anggota of a team whose lomba still waits for Kesiswaan has no `invited_at` yet (D-148),
     * as on Lomba saya and Beranda.
     */
    private function isMember(Registration $registration): bool
    {
        $student = auth()->user()?->loadMissing('student')->getRelation('student');

        return $student !== null
            && Gate::allows('view', $registration)
            && $registration->members()->where('student_id', $student->getKey())->whereNotNull('invited_at')->exists();
    }
}
