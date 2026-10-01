<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Pendaftaran;

use App\Actions\Registrations\CancelRegistration;
use App\Actions\Registrations\CreateRegistration;
use App\Actions\Registrations\InviteMembers;
use App\Enums\MemberRole;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RegistrationType;
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
 * Member search (SECURITY §3m-3): active students only, name ≥ 3 letters or an exact NIS/NISN,
 * ≤ 10 results, only ULID + name + class reach the browser, 30/min and 300/day per user.
 */
class Form extends Component
{
    public const SEARCH_PER_MINUTE = 30;

    public const SEARCH_PER_DAY = 300;

    public const MAX_RESULTS = 10;

    #[Locked]
    public string $competitionId = '';

    public string $type = 'individu';

    /** @var list<string> invited anggota (student ULIDs); re-validated by CreateRegistration */
    public array $members = [];

    public string $q = '';

    /** @var list<array{id: string, name: string, kelas: string, busy: bool, declined: bool}> */
    public array $hasil = [];

    public ?string $searchNote = null;

    public ?int $mentorId = null;

    public bool $setuju = false;

    public function mount(string $competitionId): void
    {
        $competition = Competition::query()->findOrFail($competitionId);
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $this->competitionId = $competition->getKey();
        $this->type = $competition->participation === Participation::Tim ? RegistrationType::Tim->value : RegistrationType::Individu->value;
        $this->mentorId = $competition->default_mentor_id !== null ? (int) $competition->default_mentor_id : null;
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
            $this->searchNote = 'Ketik minimal 3 huruf nama, atau NIS/NISN lengkap.';

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
                fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('nis', $term)->orWhere('nisn', $term)),
                fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->with(['user:id,name', 'classroom:id,name'])
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

        $this->reset('q', 'hasil', 'searchNote');
        $this->dispatch('sth-toast', message: 'Undangan terkirim ke '.$candidate['name'].'.', tone: 'success');
    }

    public function kirim(CreateRegistration $create): void
    {
        $competition = $this->competition();
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $this->validate(
            ['setuju' => ['accepted'], 'mentorId' => ['required', 'integer'], 'type' => ['required', 'in:individu,tim']],
            ['setuju.accepted' => 'Centang dulu pernyataan data benar.', 'mentorId.required' => 'Pilih guru pembimbing.', 'mentorId.integer' => 'Pilih guru pembimbing.'],
        );

        if (Gate::denies('create', [Registration::class, $competition])) {
            $this->addError('competition', $competition->isOpenForRegistration()
                ? 'Kamu sudah terdaftar di lomba ini.'
                : 'Pendaftaran lomba ini sudah ditutup.');

            return;
        }

        $create->handle(
            auth()->user(),
            $competition,
            RegistrationType::from($this->type),
            $this->type === RegistrationType::Tim->value ? $this->members : [],
            $this->mentorId,
        );

        $this->reset('members', 'q', 'hasil', 'searchNote', 'setuju');
        $this->dispatch('sth-toast', message: 'Pendaftaran terkirim.', tone: 'success');
    }

    public function batalkan(CancelRegistration $cancel): void
    {
        $registration = $this->current();

        if ($registration === null || Gate::denies('cancel', $registration)) {
            $this->addError('registration', 'Pendaftaran ini sudah tidak bisa dibatalkan.');

            return;
        }

        $cancel->handle(auth()->user(), $registration);

        $this->dispatch('sth-modal-close', id: 'batal-daftar');
        $this->dispatch('sth-toast', message: 'Pendaftaran dibatalkan.', tone: 'info');
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
            'canCreate' => $current === null && Gate::allows('create', [Registration::class, $competition]),
            'teamAllowed' => $competition->allowsRegistrationType(RegistrationType::Tim),
            'individuAllowed' => $competition->allowsRegistrationType(RegistrationType::Individu),
            'maxTeam' => $competition->max_team_size,
            'teamFull' => $this->teamFull(),
            'memberRows' => $this->memberRows(),
            'mentors' => $this->mentorOptions(),
        ]);
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
        return User::query()->gurus()->canLogIn()->with('staffProfile:id,user_id,honorific')->orderBy('name')->get(['id', 'name'])
            ->mapWithKeys(fn (User $u): array => [$u->id => trim(($u->staffProfile?->honorific ? $u->staffProfile->honorific.' ' : '').$u->name)])
            ->all();
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

        $lombaSaya = Route::has('siswa.lomba-saya.show')
            ? route('siswa.lomba-saya.show', $registration)
            : (Route::has('siswa.lomba-saya.index') ? route('siswa.lomba-saya.index') : null);

        return [
            'isLeader' => $mine?->role === MemberRole::Ketua,
            'pendingInvite' => $mine !== null && $mine->role === MemberRole::Anggota && $mine->confirmed_at === null && $registration->status === RegistrationStatus::MenungguKonfirmasi,
            'isTeam' => $registration->isTeam(),
            'label' => $registration->status->label(),
            'tone' => $registration->status->tone(),
            'waitingMembers' => $registration->status === RegistrationStatus::MenungguKonfirmasi,
            'membersText' => $anggota->count() === 0 ? null : "{$confirmed} dari {$anggota->count()} anggota sudah konfirmasi",
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
                    'name' => (string) $m->student?->user?->name,
                    'meta' => trim(($m->student?->classroom?->name ?? '').' · '.$m->role?->label(), ' ·'),
                    'state' => $m->declined_at !== null ? 'declined' : ($m->confirmed_at !== null ? 'confirmed' : 'pending'),
                ])
                ->values()
                ->all(),
        ];
    }
}
