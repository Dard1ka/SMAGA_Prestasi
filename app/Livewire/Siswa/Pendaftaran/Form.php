<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Pendaftaran;

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\ExpirePendingInvitations;
use App\Actions\Registrations\InviteMembers;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\WithdrawInvitation;
use App\Enums\CompetitionStatus;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Models\Competition;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\Student;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Daftar lomba" panel on Detail lomba (board Detail-Lomba): individu/tim, team member search,
 * guru pembimbing, confirmation checkbox → CreateRegistration. After sending (or when the student is
 * already in a registration of this lomba) it shows the status + next steps, and lets the leader
 * cancel while nobody has decided yet (CancelRegistration).
 *
 * Member search (SECURITY §3m-3): active students only, name ≥ 3 letters or an exact NISN,
 * ≤ 10 results, only ULID + name + class reach the browser, 30/min and 300/day per user. A student who has not
 * activated the account is marked (QA6): the invitation waits until that student activates.
 *
 * After every change of the registration (send, cancel, invite, withdraw) it dispatches CHANGED, so the page around
 * it (Detail lomba) counts the registrants again (QA6).
 *
 * "Batalkan undangan" (the × beside an unanswered invitation) asks first (D-128, lead 2026-10-10): the invitee is told
 * at once and a team whose other members all confirmed goes to the Kepala Sekolah with that click. askBatalkanUndangan()
 * opens WITHDRAW_DIALOG; batalkanUndangan() is the confirm step and does nothing without that question.
 */
class Form extends Component
{
    public const CHANGED = 'pendaftaran-berubah';

    /** id of the "Batalkan undangan?" confirm dialog (<x-sth.modal>). */
    public const WITHDRAW_DIALOG = 'batal-undangan';

    public const SEARCH_PER_MINUTE = 30;

    public const SEARCH_PER_DAY = 300;

    public const MAX_RESULTS = 10;

    #[Locked]
    public string $competitionId = '';

    public string $type = 'individu';

    /** @var list<string> invited anggota (student ULIDs); re-validated by CreateRegistration */
    public array $members = [];

    public string $q = '';

    /** @var list<array{id: string, name: string, kelas: string, busy: bool, declined: bool, pending: bool}> */
    public array $hasil = [];

    public ?string $searchNote = null;

    public ?int $mentorId = null;

    public bool $setuju = false;

    /** The invited student (ULID) the open "Batalkan undangan?" dialog asks about; set only by askBatalkanUndangan(). */
    #[Locked]
    public ?string $withdrawingId = null;

    public function mount(string $competitionId): void
    {
        $competition = Competition::query()->findOrFail($competitionId);
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $this->competitionId = $competition->getKey();
        $this->type = $competition->participation === Participation::Tim ? RegistrationType::Tim->value : RegistrationType::Individu->value;
        // QA10: pre-fill the lomba's guru pembimbing only while the select offers it (an active guru); a disabled one
        // used to stay selected out of sight and CreateRegistration refused the form without a visible message.
        $default = $competition->default_mentor_id !== null ? (int) $competition->default_mentor_id : null;
        $this->mentorId = $default !== null && array_key_exists($default, $this->mentorOptions()) ? $default : null;
    }

    public function updatedType(): void
    {
        $competition = $this->competition();

        if (! $competition->allowsRegistrationType(RegistrationType::tryFrom($this->type) ?? RegistrationType::Individu)) {
            $this->type = $competition->participation === Participation::Tim ? RegistrationType::Tim->value : RegistrationType::Individu->value;
        }

        if ($this->type === RegistrationType::Individu->value) {
            $this->reset('members', 'q', 'hasil', 'searchNote');
        }
    }

    public function updatedQ(): void
    {
        $this->hasil = [];
        $this->searchNote = null;
        $term = trim(mb_substr($this->q, 0, 60));

        if ($term === '') {
            return;
        }

        $digits = ctype_digit($term);

        if (! $digits && mb_strlen($term) < 3) {
            $this->searchNote = 'Ketik minimal 3 huruf nama, atau NISN lengkap.';

            return;
        }

        $userId = (string) auth()->id();

        if (RateLimiter::tooManyAttempts('anggota-menit:'.$userId, self::SEARCH_PER_MINUTE)
            || RateLimiter::tooManyAttempts('anggota-hari:'.$userId, self::SEARCH_PER_DAY)) {
            $this->searchNote = 'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.';

            return;
        }

        RateLimiter::hit('anggota-menit:'.$userId, 60);
        RateLimiter::hit('anggota-hari:'.$userId, 86400);

        $me = $this->student();
        $competition = $this->competition();

        $found = Student::query()
            ->active()
            ->whereKeyNot($me?->getKey())
            ->when($digits,
                fn (Builder $q) => $q->where('nisn', $term),
                fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->with(['user:id,name,status', 'classroom:id,name'])
            ->limit(self::MAX_RESULTS)
            ->get();

        $busy = Student::query()->whereKey($found->modelKeys())->busyIn($competition)->pluck('id')->all();
        // B3 (#9): people who declined the running team cannot be invited again.
        $declined = $this->current()?->members->whereNotNull('declined_at')->pluck('student_id')->all() ?? [];

        $this->hasil = $found
            ->sortBy(fn (Student $s): string => (string) $s->user?->name)
            ->map(fn (Student $s): array => [
                'id' => (string) $s->getKey(),
                'name' => (string) $s->user?->name,
                'kelas' => (string) $s->classroom?->name,
                'busy' => in_array($s->getKey(), $busy, true),
                'declined' => in_array($s->getKey(), $declined, true),
                'pending' => $s->user?->status === UserStatus::PendingActivation,
            ])
            ->values()
            ->all();

        if ($this->hasil === []) {
            $this->searchNote = 'Tidak ada akun yang cocok.';
        }
    }

    public function tambah(string $studentId): void
    {
        $candidate = collect($this->hasil)->firstWhere('id', $studentId);

        if ($candidate === null || $candidate['busy'] || in_array($studentId, $this->members, true)) {
            return;
        }

        if ($this->teamFull()) {
            $this->searchNote = 'Tim sudah penuh. Hapus anggota untuk mengganti.';

            return;
        }

        $this->members[] = $studentId;
        $this->reset('q', 'hasil', 'searchNote');
    }

    public function hapus(string $studentId): void
    {
        $this->members = array_values(array_filter($this->members, fn (string $id): bool => $id !== $studentId));
    }

    /** B3 (#9): the ketua invites a (replacement) anggota from the search results while the team waits. */
    public function undang(string $studentId, InviteMembers $invite): void
    {
        $registration = $this->current();
        $candidate = collect($this->hasil)->firstWhere('id', $studentId);

        if ($registration === null || Gate::denies('invite', $registration)) {
            $this->addError('members', 'Anggota tidak bisa diundang lagi untuk pendaftaran ini.');

            return;
        }

        if ($candidate === null || $candidate['busy'] || ($candidate['declined'] ?? false)) {
            return;
        }

        try {
            $invite->handle(auth()->user(), $registration, [$studentId]);
        } catch (ValidationException $e) {
            $this->addError('members', (string) collect($e->errors())->flatten()->first());

            return;
        }

        $this->withdrawingId = null;
        $this->reset('q', 'hasil', 'searchNote');
        $this->dispatch('sth-toast', message: 'Undangan terkirim ke '.$candidate['name'].'.', tone: 'success');
        $this->dispatch(self::CHANGED);
    }

    /**
     * The × beside an unanswered invitation (D-128): opens the dialog, nothing is withdrawn and nobody is told before
     * batalkanUndangan(). A row that is no open invitation any more gets the sentence WithdrawInvitation would give,
     * so the dialog never opens over a refusal (the Action still checks everything again under its lock).
     */
    public function askBatalkanUndangan(string $studentId): void
    {
        $this->resetErrorBag('members');
        $this->withdrawingId = null;
        $registration = $this->current();

        if ($registration === null || Gate::denies('invite', $registration)) {
            $this->refuseWithdrawal($registration);

            return;
        }

        /** @var RegistrationMember|null $member */
        $member = $registration->members->firstWhere('student_id', $studentId);
        $name = (string) $member?->student?->user?->name;

        $refusal = match (true) {
            $member === null || $member->role !== MemberRole::Anggota => 'Undangan tidak ditemukan.',
            $member->confirmed_at !== null => $name.' sudah konfirmasi, undangannya tidak bisa dibatalkan.',
            $member->declined_at !== null => $name.' sudah menolak undangan ini.',
            default => null,
        };

        if ($refusal !== null) {
            $this->addError('members', $refusal);

            return;
        }

        $this->withdrawingId = (string) $member->student_id;
        $this->dispatch('sth-modal-open', id: self::WITHDRAW_DIALOG);
    }

    /**
     * QA6 (#66): the ketua withdraws an invitation nobody answered yet (WithdrawInvitation re-checks everything).
     * D-128: the confirm step of the dialog. Without a question from askBatalkanUndangan() it returns without a word
     * (never an error page), so a second click after the withdrawal went through changes nothing.
     * The toast names the hand-over to the Kepala Sekolah when the withdrawal caused it: another invitee may confirm
     * while the dialog is open, and then the dialog's "Kamu bisa mengundangnya lagi" no longer holds.
     */
    public function batalkanUndangan(WithdrawInvitation $withdraw): void
    {
        $studentId = $this->withdrawingId;
        $this->withdrawingId = null;
        $this->dispatch('sth-modal-close', id: self::WITHDRAW_DIALOG);

        if ($studentId === null) {
            return;
        }

        $this->resetErrorBag('members');
        $registration = $this->current();

        if ($registration === null || Gate::denies('invite', $registration)) {
            $this->refuseWithdrawal($registration);

            return;
        }

        $name = (string) $registration->members->firstWhere('student_id', $studentId)?->student?->user?->name;

        try {
            $withdraw->handle(auth()->user(), $registration, $studentId);
        } catch (ValidationException $e) {
            $this->addError('members', (string) collect($e->errors())->flatten()->first());

            return;
        }

        // The status WithdrawInvitation wrote under its lock (it copies the locked row back into $registration).
        $forwarded = $registration->status === RegistrationStatus::MenungguKepsek;

        $this->dispatch('sth-toast', message: 'Undangan untuk '.$name.' dibatalkan.'.($forwarded ? ' Pendaftaran diteruskan ke Kepala Sekolah.' : ''), tone: 'success');
        $this->dispatch(self::CHANGED);
    }

    public function kirim(CreateRegistration $create): void
    {
        $competition = $this->competition();

        // `competitionId` is #[Locked] and was visible in mount(): a lomba that is no longer visible was closed by
        // Kesiswaan meanwhile (only a published lomba is visible to a student who did not submit it). That used to
        // answer a full-screen 404 (QA9); say it like a registration deadline that passed.
        if (Gate::denies('view', $competition)) {
            $this->addError('competition', 'Pendaftaran lomba ini sudah ditutup.');

            return;
        }

        $this->validate(
            ['setuju' => ['accepted'], 'mentorId' => ['required', 'integer'], 'type' => ['required', 'in:individu,tim']],
            ['setuju.accepted' => 'Centang dulu pernyataan data benar.', 'mentorId.required' => 'Pilih guru pembimbing.', 'mentorId.integer' => 'Pilih guru pembimbing.'],
        );

        if (Gate::denies('create', [Registration::class, $competition])) {
            $this->addError('competition', $competition->isOpenForRegistrationBy(auth()->user())
                ? 'Kamu sudah terdaftar di lomba ini.'
                : 'Pendaftaran lomba ini sudah ditutup.');

            return;
        }

        try {
            $create->handle(
                auth()->user(),
                $competition,
                RegistrationType::from($this->type),
                $this->type === RegistrationType::Tim->value ? $this->members : [],
                $this->mentorId,
            );
        } catch (ValidationException $e) {
            $this->showRefusal($e);

            return;
        }

        $this->reset('members', 'q', 'hasil', 'searchNote', 'setuju');
        $this->dispatch('sth-toast', message: 'Pendaftaran terkirim.', tone: 'success');
        $this->dispatch(self::CHANGED);
    }

    public function batalkan(CancelRegistration $cancel): void
    {
        $registration = $this->current();

        if ($registration === null || Gate::denies('cancel', $registration)) {
            $this->addError('registration', 'Pendaftaran ini sudah tidak bisa dibatalkan.');

            return;
        }

        $cancel->handle(auth()->user(), $registration);

        $this->withdrawingId = null;
        $this->dispatch('sth-modal-close', id: 'batal-daftar');
        $this->dispatch('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'info');
        $this->dispatch(self::CHANGED);
    }

    public function render(): View
    {
        $competition = $this->competition();
        $me = $this->student();
        $current = $this->current();

        return view('livewire.siswa.pendaftaran.form', [
            'competition' => $competition,
            'window' => LombaPresenter::window($competition),
            'closesShort' => $competition->registration_closes_at?->locale('id')->translatedFormat('j M'),
            'me' => $me,
            'current' => $current,
            'status' => $current !== null ? $this->statusView($current, $me) : null,
            'withdrawing' => $this->withdrawing($current),
            'canCreate' => $current === null && Gate::allows('create', [Registration::class, $competition]),
            // D-148: the student's own lomba still waits for Kesiswaan; a registration sent now waits with it.
            'lombaWaits' => $competition->status === CompetitionStatus::MenungguVerifikasi,
            'teamAllowed' => $competition->allowsRegistrationType(RegistrationType::Tim),
            'individuAllowed' => $competition->allowsRegistrationType(RegistrationType::Individu),
            'maxTeam' => $competition->max_team_size,
            'teamFull' => $this->teamFull(),
            'memberRows' => $this->memberRows(),
            'mentors' => $this->mentorOptions(),
        ]);
    }

    /**
     * The team can no longer be changed, so an invitation cannot be withdrawn (the ask and the confirm step of the
     * dialog). The block that prints `members` errors is drawn only while the ketua may still invite: once the team
     * went to the Kepala Sekolah the sentence would never be read there, so it is also said in a toast.
     */
    private function refuseWithdrawal(?Registration $registration): void
    {
        $message = $registration?->status === RegistrationStatus::MenungguKepsek
            ? 'Undangan tidak bisa dibatalkan lagi: tim sudah lengkap dan diteruskan ke Kepala Sekolah.'
            : 'Undangan tidak bisa dibatalkan lagi untuk pendaftaran ini.';

        $this->addError('members', $message);
        $this->dispatch('sth-toast', message: $message, tone: 'warning');
    }

    /**
     * Texts of the "Batalkan undangan?" dialog (D-128), or null while nothing is asked: the invitee, whether the team
     * goes to the Kepala Sekolah with this withdrawal, and the notice line, which promises a notice only when the
     * invitee is told (D-109).
     *
     * @return array{name: string, ready: bool, notice: string}|null
     */
    private function withdrawing(?Registration $registration): ?array
    {
        if ($registration === null || $this->withdrawingId === null) {
            return null;
        }

        /** @var RegistrationMember|null $member */
        $member = $registration->members->firstWhere('student_id', $this->withdrawingId);

        if ($member === null || $member->confirmed_at !== null || $member->declined_at !== null) {
            return null;
        }

        $name = (string) $member->student?->user?->name;
        // What WithdrawInvitation does next (ConfirmMembership::advanceIfTeamReady(), D-36): everyone who is left has
        // confirmed and at least two people remain → the team goes to the Kepala Sekolah and its roster is closed.
        $remaining = $registration->members->whereNull('declined_at')->reject(fn (RegistrationMember $m): bool => $m->is($member));
        // The same question WithdrawInvitation asks before it notifies the invitee.
        $told = User::query()->canLogIn()->whereHas('student', fn (Builder $q) => $q->whereKey($this->withdrawingId))->exists();

        return [
            'name' => $name,
            'ready' => $remaining->count() >= 2 && $remaining->every(fn (RegistrationMember $m): bool => $m->confirmed_at !== null),
            // A student is never told another student's account status (SECURITY §3m-3; as on Undangan tim, QA10):
            // when nobody is told the promise is left out, without a reason. It must stay a non-empty sentence: the
            // view falls back to its default promise on null, and the dialog's aria-describedby needs the paragraph.
            'notice' => $told ? $name.' langsung diberi tahu.' : 'Undangan ini belum dijawab.',
        ];
    }

    /**
     * QA10: put every refusal of CreateRegistration where the panel shows it. The Action names the guru `mentor` (the
     * select is `mentorId`), `type` has no field, and `members` shows only in the team part of the form; anything the
     * panel has no place for goes to the message above the form.
     */
    private function showRefusal(ValidationException $e): void
    {
        foreach ($e->errors() as $key => $messages) {
            $field = match (true) {
                $key === 'mentor' => 'mentorId',
                $key === 'members' && $this->type === RegistrationType::Tim->value => 'members',
                $key === 'registration' => 'registration',
                default => 'competition',
            };

            foreach ($messages as $message) {
                $this->addError($field, (string) $message);
            }
        }
    }

    private function competition(): Competition
    {
        return Competition::query()->findOrFail($this->competitionId);
    }

    private function student(): ?Student
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->loadMissing('student.classroom')->getRelation('student');
    }

    /** The student's registration for this lomba that is still running (declined invites excluded). */
    private function current(): ?Registration
    {
        $me = $this->student();

        if ($me === null) {
            return null;
        }

        return Registration::query()
            ->where('competition_id', $this->competitionId)
            ->nonFinal()
            ->whereHas('members', fn (Builder $m) => $m->where('student_id', $me->getKey())->whereNull('declined_at'))
            ->with(['competition', 'members.student.user:id,name', 'members.student.classroom:id,name', 'mentor.staffProfile'])
            ->latest()
            ->first();
    }

    private function teamFull(): bool
    {
        $max = $this->competition()->max_team_size;

        return $max !== null && $max > 0 && count($this->members) + 1 >= $max;
    }

    /** @return list<array{id: string, name: string, kelas: string, initials: string}> */
    private function memberRows(): array
    {
        if ($this->members === []) {
            return [];
        }

        $students = Student::query()->whereKey($this->members)->with(['user:id,name', 'classroom:id,name'])->get()->keyBy('id');

        return collect($this->members)
            ->filter(fn (string $id): bool => $students->has($id))
            ->map(fn (string $id): array => [
                'id' => $id,
                'name' => (string) $students[$id]->user?->name,
                'kelas' => (string) $students[$id]->classroom?->name,
                'initials' => Navigation::initials((string) $students[$id]->user?->name),
            ])
            ->values()
            ->all();
    }

    /** @return array<int, string> */
    private function mentorOptions(): array
    {
        // The same list the Kepala Sekolah picks from when replacing the guru (D-132).
        return MentorChoice::options();
    }

    /** @return array<string, mixed> */
    private function statusView(Registration $registration, ?Student $me): array
    {
        $members = $registration->members;
        $mine = $members->firstWhere('student_id', $me?->getKey());
        $anggota = $members->filter(fn (RegistrationMember $m): bool => $m->role === MemberRole::Anggota && $m->declined_at === null);
        $confirmed = $anggota->whereNotNull('confirmed_at')->count();
        $mentor = $registration->mentor;

        // B3 (#9): the ketua sees the whole team (incl. who declined) and may invite replacements.
        $canInvite = Gate::allows('invite', $registration);
        $max = $registration->competition?->max_team_size;
        $active = $members->whereNull('declined_at')->count();
        // QA10: an invitation the scheduler expired at the deadline (D-50) reads "Tidak menjawab", not "Menolak".
        $expired = $canInvite ? ExpirePendingInvitations::expiredMemberIds($registration) : [];

        $lombaSaya = Route::has('siswa.lomba-saya.show')
            ? route('siswa.lomba-saya.show', $registration)
            : (Route::has('siswa.lomba-saya.index') ? route('siswa.lomba-saya.index') : null);

        $waitingLomba = $registration->status === RegistrationStatus::MenungguLomba;

        return [
            'isLeader' => $mine?->role === MemberRole::Ketua,
            'pendingInvite' => $mine !== null && $mine->role === MemberRole::Anggota && $mine->confirmed_at === null && $registration->status === RegistrationStatus::MenungguKonfirmasi,
            'isTeam' => $registration->isTeam(),
            'label' => $registration->status->label(),
            'tone' => $registration->status->tone(),
            'waitingMembers' => $waitingLomba || $registration->status === RegistrationStatus::MenungguKonfirmasi,
            // D-148: nothing goes out before Kesiswaan decides the lomba, so no confirmation count yet.
            'membersText' => $anggota->count() === 0 || $waitingLomba ? null : "{$confirmed} dari {$anggota->count()} anggota sudah konfirmasi",
            'waitingText' => $waitingLomba
                ? 'Pendaftaran diteruskan setelah Kesiswaan memverifikasi lomba ini.'.($registration->isTeam() ? ' Undangan tim dikirim saat itu.' : '')
                : null,
            'cancelNote' => $waitingLomba
                ? 'Pendaftaran ini belum diteruskan, jadi tidak ada yang diberi tahu.'
                : 'Anggota tim akan diberi tahu. Kamu bisa mendaftar lagi selama pendaftaran masih dibuka.',
            'mentor' => $mentor === null ? '–' : trim(($mentor->staffProfile?->honorific ? $mentor->staffProfile->honorific.' ' : '').$mentor->name),
            'leaderName' => (string) $members->firstWhere('role', MemberRole::Ketua)?->student?->user?->name,
            'canCancel' => Gate::allows('cancel', $registration),
            'phase' => $registration->status->phase(),
            'lombaSaya' => $lombaSaya,
            'undangan' => route('siswa.undangan.show', $registration),
            'canInvite' => $canInvite,
            'slotsLeft' => $max !== null && $max > 0 ? max(0, $max - $active) : null,
            'team' => ! $canInvite ? [] : $members
                ->sortBy(fn (RegistrationMember $m): string => ($m->role === MemberRole::Ketua ? '0' : '1').($m->invited_at?->format('YmdHis') ?? ''))
                ->map(fn (RegistrationMember $m): array => [
                    'id' => (string) $m->getKey(),
                    'studentId' => (string) $m->student_id,
                    'name' => (string) $m->student?->user?->name,
                    'meta' => trim(($m->student?->classroom?->name ?? '').' · '.$m->role?->label(), ' ·'),
                    'state' => match (true) {
                        in_array((string) $m->getKey(), $expired, true) => 'expired',
                        $m->declined_at !== null => 'declined',
                        $m->confirmed_at !== null => 'confirmed',
                        default => 'pending',
                    },
                ])
                ->values()
                ->all(),
        ];
    }
}
