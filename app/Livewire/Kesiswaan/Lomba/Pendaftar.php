<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan\Lomba;

use App\Actions\Registrations\ChangeMentor;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RejectRegistration;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * SV8, D-198: "N siswa" on a Kelola lomba row (child of Lomba\Index) lists the registrations of that lomba with their
 * guru pembimbing, and "Isi / Ganti guru pembimbing" sets or replaces the guru there (ChangeMentor; Kesiswaan + Admin,
 * `registration.mentor`) until the laporan hasil is sent (ChangeMentor::STATUSES; an issued letter does not stop it
 * since 2026-11-09, Tata Usaha is then told to make a Koreksi). The question is the dialog MENTOR_DIALOG (D-128:
 * askMentor() → changeMentor(), a silent no-op without the question), which says who is told (D-134: never the guru who
 * was replaced; Tata Usaha only for a registration with a guru letter). A row a colleague, the ketua, an approver or a
 * report sent meanwhile changed since the list showed it is answered with a warning toast and a fresh list
 * (changedElsewhere()), never an error page; a letter issued meanwhile is no reason to refuse.
 */
class Pendaftar extends Component
{
    public const DIALOG = 'lomba-pendaftar';

    public const MENTOR_DIALOG = 'ganti-guru-pembimbing';

    /** Opened by Lomba\Index::showRegistrations() with the lomba id. */
    public const OPEN_EVENT = 'lomba-pendaftar-open';

    public const STEP = 20;

    public const MAX = 200;

    public const MSG_STALE_MENTOR = 'Guru pembimbing pendaftaran ini baru saja diganti. Daftar sudah diperbarui.';

    #[Locked]
    public ?string $competitionId = null;

    /**
     * @var array<string, int|null> the rows the list showed since it was opened: registration id => the guru id it
     *                              showed last (QA9 stale check; any other id answers 404). A row that left the list
     *                              (cancelled meanwhile) stays here, so a click on it gets the toast, not a 404.
     */
    #[Locked]
    public array $shown = [];

    /** The registration the open MENTOR_DIALOG asks about; set only by askMentor(). */
    #[Locked]
    public ?string $mentorFor = null;

    /** The guru that registration had when the question was asked (null = none): changeMentor() compares with it. */
    #[Locked]
    public ?int $mentorAsked = null;

    /** The guru picked in that dialog (a user id; ChangeMentor checks it again). */
    public string $mentorChoice = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        $this->authorize('manageMentors', Registration::class);
    }

    #[On(self::OPEN_EVENT)]
    public function open(string $id): void
    {
        $this->authorize('manageMentors', Registration::class);

        $competition = Competition::query()->find($id);
        abort_if($competition === null, 404);

        $this->competitionId = (string) $competition->getKey();
        $this->limit = self::STEP;
        $this->shown = [];
        $this->mentorFor = null;
        $this->mentorAsked = null;
        $this->mentorChoice = '';
        $this->resetErrorBag();
        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    public function showMore(): void
    {
        $this->authorize('manageMentors', Registration::class);
        $this->limit = min(self::MAX, $this->limit + self::STEP);
    }

    /** "Isi / Ganti guru pembimbing" on a row: opens the question. Nothing changes and nobody is told before changeMentor(). */
    public function askMentor(string $id): void
    {
        $this->mentorFor = null;
        $this->mentorAsked = null;
        $this->mentorChoice = '';
        $this->resetErrorBag();
        $registration = $this->findShown($id);

        if ($this->changedElsewhere($registration, $this->shown[$id] ?? null)) {
            return;
        }

        $this->authorize('assignMentor', $registration);

        $this->mentorFor = (string) $registration->getKey();
        $this->mentorAsked = $registration->mentor_id !== null ? (int) $registration->mentor_id : null;
        $this->dispatch('sth-modal-open', id: self::MENTOR_DIALOG);
    }

    /**
     * "Tetapkan guru" in the dialog. Without a question opened by askMentor() there is nothing to confirm: it returns
     * without a word (never a 404), so a second click after the change went through puts no error page over it.
     */
    public function changeMentor(ChangeMentor $change): void
    {
        if ($this->mentorFor === null) {
            return;
        }

        $this->resetErrorBag();
        $registration = $this->findShown($this->mentorFor);

        if ($this->changedElsewhere($registration, $this->mentorAsked)) {
            $this->closeMentor();

            return;
        }

        if (trim($this->mentorChoice) === '') {
            $this->addError('mentorChoice', ChangeMentor::MSG_CHOOSE);

            return;
        }

        $hadMentor = $registration->mentor_id !== null;

        try {
            $change->handle($this->user(), $registration, $this->mentorChoice);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            if (isset($errors['mentor'])) {
                // The guru left the list since the dialog opened (account switched off, role changed): pick again.
                $this->mentorChoice = '';
                $this->addError('mentorChoice', (string) $errors['mentor'][0]);

                return;
            }

            $this->closeMentor();
            $this->dispatch('sth-toast', message: (string) collect($errors)->flatten()->first().' Daftar sudah diperbarui.', tone: 'warning');

            return;
        }

        $this->closeMentor();

        // What the Action wrote and whom its rules told, not what the dialog predicted (D-109).
        $name = (string) MentorChoice::name($registration->mentor()->with('staffProfile')->first());
        $students = RejectRegistration::studentsTold($registration)['told'] > 0;
        $guru = ChangeMentor::mentorToTell($registration, $this->user()) !== null;
        $tu = ChangeMentor::guruLetterIssuedAtLastChange($registration) && ChangeMentor::tataUsahaToTell($this->user())->isNotEmpty();

        $this->dispatch('sth-toast', message: ($hadMentor ? 'Guru pembimbing diganti: ' : 'Guru pembimbing diisi: ')
            .MentorChoice::closing($name).' '.self::toldSentence($students, $guru, $tu), tone: 'success');
    }

    public function render(): View
    {
        $competition = $this->competitionId !== null ? Competition::query()->find($this->competitionId) : null;
        $this->limit = max(self::STEP, min(self::MAX, $this->limit));
        $registrations = $competition !== null ? $this->registrations($competition) : new Collection;
        $this->shown = array_replace($this->shown, $registrations
            ->mapWithKeys(fn (Registration $r): array => [(string) $r->getKey() => $r->mentor_id !== null ? (int) $r->mentor_id : null])
            ->all());
        $total = $competition !== null ? $this->listed($competition)->count() : 0;

        return view('livewire.kesiswaan.lomba.pendaftar', [
            'lomba' => $competition?->name,
            'rows' => $registrations->map(fn (Registration $r): array => $this->row($r))->all(),
            'more' => $total > $registrations->count() && $this->limit < self::MAX,
            'mentorDialog' => $this->mentorDialog($registrations),
        ]);
    }

    /** @return array<string, mixed> one row of the list */
    private function row(Registration $registration): array
    {
        $members = self::visibleMembers($registration);

        return [
            'id' => (string) $registration->getKey(),
            'who' => self::who($members),
            'team' => $registration->isTeam(),
            'status' => [$registration->status->tone(), $registration->status->label()],
            'mentor' => MentorChoice::name($registration->mentor),
            'canChange' => ChangeMentor::problem($registration) === null && $this->user()->can('assignMentor', $registration),
        ];
    }

    /**
     * The question of MENTOR_DIALOG, or null when nothing is asked (or the row left the list). The sentences promise a
     * notice only to the people who get one (D-109), and say nothing about the guru who is replaced (D-134). Tata Usaha
     * is named only for a registration with a guru letter that is not revoked (`has_guru_letter`), the case ChangeMentor
     * tells it: only that letter prints the guru, a student letter alone asks for no Koreksi.
     *
     * @param  Collection<int, Registration>  $registrations
     * @return array{title: string, who: string, current: ?string, options: array<int, string>, notices: list<string>}|null
     */
    private function mentorDialog(Collection $registrations): ?array
    {
        if ($this->mentorFor === null) {
            return null;
        }

        /** @var Registration|null $registration */
        $registration = $registrations->firstWhere('id', $this->mentorFor);

        if ($registration === null || ChangeMentor::problem($registration) !== null) {
            return null;
        }

        $current = $registration->mentor;
        ['told' => $told, 'of' => $of] = RejectRegistration::studentsTold($registration);

        return [
            'title' => $current !== null ? 'Ganti guru pembimbing' : 'Isi guru pembimbing',
            'who' => self::who(self::visibleMembers($registration)),
            'current' => MentorChoice::name($current),
            // Every other guru: not the one the row has, and not this account itself (nobody is told about their own
            // action, so the sentence about the new guru would not hold for it).
            'options' => MentorChoice::options(...array_values(array_filter([
                $current !== null ? (int) $current->getKey() : null,
                (int) $this->user()->getKey(),
            ]))),
            'notices' => array_values(array_filter([
                match (true) {
                    $told === 0 => 'Akun siswa tidak aktif, jadi siswa tidak menerima notifikasi.',
                    $told < $of => 'Siswa yang akunnya aktif diberi tahu siapa guru pembimbingnya sekarang.',
                    default => 'Siswa diberi tahu siapa guru pembimbingnya sekarang.',
                },
                $registration->isKnownToMentor()
                    ? 'Guru yang dipilih diberi tahu bahwa ia ditetapkan sebagai guru pembimbing.'
                    : 'Guru yang dipilih belum diberi tahu selama lomba ini menunggu verifikasi.',
                // The issued guru letter keeps the guru it printed (D-108): only then Tata Usaha hears of the change.
                (bool) $registration->getAttribute('has_guru_letter') && ChangeMentor::tataUsahaToTell($this->user())->isNotEmpty()
                    ? ChangeMentor::TU_TOLD
                    : null,
            ])),
        ];
    }

    /** @return Collection<int, Registration> */
    private function registrations(Competition $competition): Collection
    {
        return $this->listed($competition)
            ->with([
                'mentor.staffProfile',
                // Not a member who declined, not a student in the trash of Admin › Kelas (whereHas: Student excludes it).
                'members' => fn ($q) => $q->whereNull('declined_at')->whereHas('student')
                    ->orderByRaw("case when role = 'ketua' then 0 else 1 end")->orderBy('created_at'),
                'members.student.user',
                'members.student.classroom',
            ])
            ->withExists(['guruLetter as has_guru_letter'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($this->limit)
            ->get();
    }

    /**
     * The registrations the "N siswa" count of Kelola lomba stands for: not cancelled, not rejected, not one whose ketua
     * is in the trash (Registration::scopeWithListedLeader()).
     *
     * @return Builder<Registration>
     */
    private function listed(Competition $competition): Builder
    {
        return Registration::query()
            ->where('competition_id', $competition->getKey())
            ->whereNotIn('status', [RegistrationStatus::Dibatalkan->value, RegistrationStatus::Ditolak->value])
            ->withListedLeader();
    }

    /**
     * The members a student screen shows: an anggota of a team waiting for its lomba was not invited yet (D-148), so
     * nobody lists that student before the invitation.
     *
     * @return \Illuminate\Support\Collection<int, RegistrationMember>
     */
    private static function visibleMembers(Registration $registration): \Illuminate\Support\Collection
    {
        return $registration->members
            ->reject(fn (RegistrationMember $m): bool => $registration->status === RegistrationStatus::MenungguLomba
                && $m->role !== MemberRole::Ketua
                && $m->invited_at === null)
            ->values();
    }

    /** @param \Illuminate\Support\Collection<int, RegistrationMember> $members */
    private static function who(\Illuminate\Support\Collection $members): string
    {
        $names = $members->map(fn (RegistrationMember $m): string => (string) ($m->student?->user?->name ?? 'Siswa')
            .($m->student?->classroom !== null ? ' ('.$m->student->classroom->name.')' : ''));

        return $names->count() > 1 ? 'Tim '.$names->count().' siswa: '.$names->implode(', ') : (string) ($names->first() ?? 'Siswa');
    }

    private static function toldSentence(bool $students, bool $guru, bool $tu): string
    {
        $sentence = match (true) {
            $students && $guru => 'Siswa dan guru pembimbing yang baru diberi tahu.',
            $students => 'Siswa diberi tahu.',
            $guru => 'Guru pembimbing yang baru diberi tahu.',
            default => $tu ? '' : 'Tidak ada notifikasi yang dikirim.',
        };

        return trim($sentence.($tu ? ' '.ChangeMentor::TU_TOLD : ''));
    }

    /**
     * A row of the list as it was shown (QA9): any other id, or a row of another lomba, is a 404. Registrations of the
     * lomba are never hidden from this screen's permission, so the 404 only answers a forged id.
     */
    private function findShown(string $id): Registration
    {
        $this->authorize('manageMentors', Registration::class);
        abort_unless($this->competitionId !== null && array_key_exists($id, $this->shown), 404);

        $registration = Registration::query()->whereKey($id)->where('competition_id', $this->competitionId)->first();
        abort_if($registration === null, 404);

        return $registration;
    }

    /**
     * The row cannot take a guru any more (the report sent, cancelled, its ketua in the trash) or another account set
     * another guru since the screen showed $expected (the list's row, or the question): say so; the render after this
     * call draws the row as it is now.
     */
    private function changedElsewhere(Registration $registration, ?int $expected): bool
    {
        $problem = ChangeMentor::problem($registration);
        $current = $registration->mentor_id !== null ? (int) $registration->mentor_id : null;
        $message = match (true) {
            $problem !== null => $problem.' Daftar sudah diperbarui.',
            $current !== $expected => self::MSG_STALE_MENTOR,
            default => null,
        };

        if ($message === null) {
            return false;
        }

        $this->dispatch('sth-toast', message: $message, tone: 'warning');

        return true;
    }

    private function closeMentor(): void
    {
        $this->mentorFor = null;
        $this->mentorAsked = null;
        $this->mentorChoice = '';
        $this->dispatch('sth-modal-close', id: self::MENTOR_DIALOG);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
