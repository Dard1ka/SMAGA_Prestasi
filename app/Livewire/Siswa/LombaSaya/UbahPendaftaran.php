<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\LombaSaya;

use App\Actions\Registrations\CancelRevision;
use App\Actions\Registrations\ChangeRegistration;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RequestRevision;
use App\Actions\Registrations\RevisionRules;
use App\Enums\MemberRole;
use App\Enums\RegistrationStatus;
use App\Enums\RevisionStatus;
use App\Enums\UserStatus;
use App\Livewire\Siswa\Pendaftaran\Form as PendaftaranForm;
use App\Models\Registration;
use App\Models\RegistrationMember;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;
use App\Policies\RegistrationPolicy;
use App\Support\StaffRoleLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * SV8, D-199: the ketua's "Ubah pendaftaran" (before the approval: ChangeRegistration, at once) and "Ajukan revisi"
 * (after it: RequestRevision, decided by Tata Usaha or Kesiswaan) on Lomba saya, below the team card. Add anggota
 * (the member search of the Pendaftaran lomba panel: active students, name ≥ 3 letters or a full NISN, ≤ 10 results,
 * the same rate limits), remove anggota, change the guru pembimbing; after the approval with a reason. The form is a
 * dialog that says what happens and who is told (D-128); a pending revision shows its state and "Batalkan revisi" (its
 * own question).
 *
 * Stale screens: the form keeps what it was opened on (`openedOn`: status, team, guru). When the registration changed
 * since (approved, an anggota answered, Kesiswaan changed the guru …) "Simpan" says so in a toast and draws the form
 * again instead of saving a change the ketua did not see. Every action re-checks through the Actions.
 */
final class UbahPendaftaran extends Component
{
    public const DIALOG = 'ubah-pendaftaran';

    public const CANCEL_DIALOG = 'batal-revisi';

    /** Dispatched after a change, so Lomba saya draws the team again. */
    public const CHANGED = 'pendaftaran-diubah';

    public const MSG_STALE = 'Pendaftaran ini baru saja berubah. Periksa lagi perubahannya sebelum menyimpan.';

    #[Locked]
    public string $registrationId = '';

    /** @var array{status: string, members: list<string>, mentor: ?int}|null what the open form was drawn from */
    #[Locked]
    public ?array $openedOn = null;

    /** @var list<string> students to add (ULIDs; re-checked by the Action) */
    public array $tambah = [];

    /** @var list<string> anggota to take out (ULIDs; re-checked by the Action) */
    public array $keluarkan = [];

    /** The guru pembimbing picked (a user id as text; the current one = no change). */
    public string $guru = '';

    public string $alasan = '';

    public string $q = '';

    /** @var list<array{id: string, name: string, kelas: string, busy: bool, inTeam: bool, declined: bool, pending: bool}> */
    public array $hasil = [];

    public ?string $searchNote = null;

    /** The pending revision the open "Batalkan revisi?" question asks about. */
    #[Locked]
    public ?string $cancelling = null;

    public function mount(string $registrationId): void
    {
        $registration = Registration::query()->find($registrationId);
        abort_if($registration === null || ! RevisionRules::leads($this->user(), $registration), 404);

        $this->registrationId = (string) $registration->getKey();
    }

    /** "Ubah pendaftaran" / "Ajukan revisi": a fresh form on the registration as it is now. */
    public function open(): void
    {
        $registration = $this->registration();
        $this->resetErrorBag();

        if (! $this->mayChange($registration)) {
            $this->dispatch('sth-toast', message: $this->refusal($registration).' Halaman sudah diperbarui.', tone: 'warning');
            $this->dispatch(self::CHANGED);

            return;
        }

        $this->fillForm($registration);
        $this->dispatch('sth-modal-open', id: self::DIALOG);
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

        // The limits of the member search of the Pendaftaran lomba panel, on the same keys (SECURITY §3m-3).
        $userId = (string) $this->user()->getKey();

        if (RateLimiter::tooManyAttempts('anggota-menit:'.$userId, PendaftaranForm::SEARCH_PER_MINUTE)
            || RateLimiter::tooManyAttempts('anggota-hari:'.$userId, PendaftaranForm::SEARCH_PER_DAY)) {
            $this->searchNote = 'Terlalu banyak pencarian. Tunggu sebentar lalu coba lagi.';

            return;
        }

        RateLimiter::hit('anggota-menit:'.$userId, 60);
        RateLimiter::hit('anggota-hari:'.$userId, 86400);

        $registration = $this->registration();
        $rows = $registration->members()->get(['id', 'student_id', 'declined_at']);
        $inTeam = $rows->whereNull('declined_at')->pluck('student_id')->map(fn (mixed $id): string => (string) $id)->all();
        $declined = $rows->whereNotNull('declined_at')->pluck('student_id')->map(fn (mixed $id): string => (string) $id)->all();

        $found = Student::query()
            ->active()
            ->whereKeyNot($registration->leader_student_id)
            ->when($digits,
                fn (Builder $q) => $q->where('nisn', $term),
                fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $u->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')))
            ->with(['user:id,name,status', 'classroom:id,name'])
            ->limit(PendaftaranForm::MAX_RESULTS)
            ->get();

        $busy = Student::query()->whereKey($found->modelKeys())->busyIn((string) $registration->competition_id)->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)->all();

        $this->hasil = $found
            ->sortBy(fn (Student $s): string => (string) $s->user?->name)
            ->map(fn (Student $s): array => [
                'id' => (string) $s->getKey(),
                'name' => (string) $s->user?->name,
                'kelas' => (string) $s->classroom?->name,
                'inTeam' => in_array((string) $s->getKey(), $inTeam, true),
                'busy' => in_array((string) $s->getKey(), $busy, true) && ! in_array((string) $s->getKey(), $inTeam, true),
                'declined' => in_array((string) $s->getKey(), $declined, true),
                'pending' => $s->user?->status === UserStatus::PendingActivation,
            ])
            ->values()
            ->all();

        if ($this->hasil === []) {
            $this->searchNote = 'Tidak ada akun yang cocok.';
        }
    }

    public function tambahAnggota(string $studentId): void
    {
        $candidate = collect($this->hasil)->firstWhere('id', $studentId);

        if ($candidate === null || $candidate['busy'] || $candidate['inTeam'] || $candidate['declined'] || in_array($studentId, $this->tambah, true)) {
            return;
        }

        $this->tambah[] = $studentId;
        $this->reset('q', 'hasil', 'searchNote');
    }

    public function hapusTambah(string $studentId): void
    {
        $this->tambah = array_values(array_filter($this->tambah, fn (string $id): bool => $id !== $studentId));
    }

    /** "Simpan perubahan" (before the approval) or "Kirim revisi" (after it). */
    public function simpan(ChangeRegistration $change, RequestRevision $request): void
    {
        $registration = $this->registration();
        $this->resetErrorBag();

        if ($this->openedOn === null) {
            return;
        }

        if ($this->snapshot($registration) !== $this->openedOn) {
            if ($this->mayChange($registration)) {
                $this->fillForm($registration);
                $this->dispatch('sth-toast', message: self::MSG_STALE, tone: 'warning');
            } else {
                $this->close();
                $this->dispatch('sth-toast', message: $this->refusal($registration).' Halaman sudah diperbarui.', tone: 'warning');
            }

            $this->dispatch(self::CHANGED);

            return;
        }

        $mentor = $this->guru === (string) ($registration->mentor_id ?? '') ? null : $this->guru;
        $before = RevisionRules::isBeforeApproval($registration);

        try {
            if ($before) {
                $result = $change->handle($this->user(), $registration, $this->tambah, $this->keluarkan, $mentor);
            } else {
                $request->handle($this->user(), $registration, $this->tambah, $this->keluarkan, $mentor, $this->alasan);
            }
        } catch (ValidationException $e) {
            $this->showRefusal($e);

            return;
        }

        $this->close();
        $this->dispatch(self::CHANGED);

        if ($before) {
            $this->dispatch('sth-toast', message: self::changedText($result), tone: 'success');

            return;
        }

        $this->dispatch('sth-toast', message: $this->tambah === []
            ? 'Revisi terkirim ke Tata Usaha dan Kesiswaan.'
            : 'Revisi terkirim. Siswa yang ditambah perlu menerima undangan dulu.', tone: 'success');
        $this->reset('tambah', 'keluarkan', 'alasan');
    }

    /** "Batalkan revisi": the question; nothing changes before cancelRevision(). */
    public function askCancelRevision(): void
    {
        $revision = $this->registration()->pendingRevision()->first();
        $this->cancelling = null;

        if ($revision === null || Gate::denies('cancel', $revision)) {
            $this->dispatch('sth-toast', message: 'Revisi ini sudah diputuskan atau dibatalkan. Halaman sudah diperbarui.', tone: 'warning');
            $this->dispatch(self::CHANGED);

            return;
        }

        $this->cancelling = (string) $revision->getKey();
        $this->dispatch('sth-modal-open', id: self::CANCEL_DIALOG);
    }

    /** The confirm step of "Batalkan revisi?"; without the question it does nothing. */
    public function cancelRevision(CancelRevision $cancel): void
    {
        $id = $this->cancelling;
        $this->cancelling = null;
        $this->dispatch('sth-modal-close', id: self::CANCEL_DIALOG);

        if ($id === null) {
            return;
        }

        $revision = RegistrationRevision::query()->whereKey($id)->where('registration_id', $this->registrationId)->first();
        abort_if($revision === null, 404);

        try {
            $cancel->handle($this->user(), $revision);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: (string) collect($e->errors())->flatten()->first().' Halaman sudah diperbarui.', tone: 'warning');
            $this->dispatch(self::CHANGED);

            return;
        }

        $this->dispatch('sth-toast', message: 'Revisi dibatalkan. Tim yang sudah disetujui tetap berlaku.', tone: 'success');
        $this->dispatch(self::CHANGED);
    }

    public function render(): View
    {
        $registration = $this->registration();
        $registration->loadMissing(['competition', 'mentor.staffProfile', 'members.student.user:id,name', 'members.student.classroom:id,name']);
        $pending = $registration->pendingRevision()->first();
        $before = RevisionRules::isBeforeApproval($registration);

        return view('livewire.siswa.lomba-saya.ubah-pendaftaran', [
            'before' => $before,
            'isTeam' => $registration->isTeam(),
            'canOpen' => $pending === null && $this->mayChange($registration),
            'pending' => $pending !== null ? $this->revisionView($pending) : null,
            'last' => $pending === null ? $this->lastDecided($registration) : null,
            'form' => $this->openedOn !== null ? $this->formView($registration) : null,
        ]);
    }

    // View models ------------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function formView(Registration $registration): array
    {
        $before = RevisionRules::isBeforeApproval($registration);
        $members = RegistrationMember::inTeamOrder($registration->members->filter(fn (RegistrationMember $m): bool => $m->student !== null && $m->declined_at === null));
        $added = Student::query()->whereKey($this->tambah)->with(['user:id,name', 'classroom:id,name'])->get()->keyBy(fn (Student $s): string => (string) $s->getKey());
        $max = $registration->competition?->teamLimit();
        $people = $members->count() - count(array_intersect($this->keluarkan, $members->pluck('student_id')->map(fn (mixed $id): string => (string) $id)->all())) + count($this->tambah);
        $letters = ! $before && $registration->letters()->exists();

        return [
            'members' => $members->map(fn (RegistrationMember $m): array => [
                'studentId' => (string) $m->student_id,
                'name' => (string) $m->student?->user?->name,
                'kelas' => (string) $m->student?->classroom?->name,
                'leader' => $m->role === MemberRole::Ketua,
                // After the approval only confirmed anggota are in the team; before it an open invitation may go too.
                'removable' => $m->role === MemberRole::Anggota && ($before || $m->confirmed_at !== null),
                'state' => $m->confirmed_at !== null ? 'Sudah konfirmasi' : ($m->invited_at === null ? 'Belum diundang' : 'Menunggu konfirmasi'),
            ])->values()->all(),
            'added' => collect($this->tambah)->filter(fn (string $id): bool => $added->has($id))->map(fn (string $id): array => [
                'id' => $id,
                'name' => (string) $added[$id]->user?->name,
                'kelas' => (string) $added[$id]->classroom?->name,
            ])->values()->all(),
            'people' => $people,
            'max' => $max,
            'full' => $max !== null && $people >= $max,
            'mentors' => MentorChoice::options(),
            'currentMentor' => MentorChoice::name($registration->mentor),
            'notices' => $this->notices($registration, $before, $letters),
            'windowClosed' => $before && $registration->status !== RegistrationStatus::MenungguLomba && ! RegistrationPolicy::teamWindowOpen($registration),
        ];
    }

    /**
     * What happens and who is told, for the changes picked so far (D-128, D-109: a notice is promised only to people
     * who get one).
     *
     * @return list<string>
     */
    private function notices(Registration $registration, bool $before, bool $letters): array
    {
        $adds = $this->tambah !== [];
        $removes = $this->keluarkan !== [];
        $waiting = $registration->status === RegistrationStatus::MenungguLomba;

        if ($before) {
            return array_values(array_filter([
                'Perubahan langsung disimpan, tanpa persetujuan tambahan.',
                $adds ? ($waiting ? 'Siswa yang ditambah diundang setelah Kesiswaan memverifikasi lomba ini.' : 'Siswa yang ditambah langsung diundang dan perlu konfirmasi.') : null,
                $adds && $registration->status === RegistrationStatus::MenungguKepsek
                    ? 'Pendaftaran kembali menunggu konfirmasi anggota baru, lalu diteruskan lagi untuk persetujuan.' : null,
                $removes ? ($waiting ? 'Siswa yang dikeluarkan belum pernah diundang, jadi tidak diberi tahu.' : 'Siswa yang dikeluarkan diberi tahu.') : null,
            ]));
        }

        return array_values(array_filter([
            'Tim yang sudah disetujui tetap berlaku sampai revisi diputuskan Tata Usaha atau Kesiswaan.',
            $adds ? 'Siswa yang ditambah perlu menerima undangan dulu sebelum revisi bisa diputuskan.' : null,
            $letters ? 'Surat tugas yang sudah terbit tidak berubah. Setelah revisi disetujui, Tata Usaha membuat Koreksi.' : null,
        ]));
    }

    /** @return array<string, mixed> the pending revision's card */
    private function revisionView(RegistrationRevision $revision): array
    {
        $ids = array_merge($revision->additions(), $revision->removals());
        $students = Student::query()->withTrashed()->whereKey($ids)->with('user:id,name')->get()->keyBy(fn (Student $s): string => (string) $s->getKey());
        $name = fn (string $id): string => (string) ($students->get($id)?->user?->name ?? 'Siswa');
        $mentorTo = $revision->mentorTo();

        return [
            'added' => array_map(fn (string $id): array => [
                'name' => $name($id),
                'state' => match ($revision->answerOf($id)) {
                    'confirmed' => ['Sudah menerima', 'green'],
                    'declined' => ['Menolak', 'red'],
                    default => ['Menunggu jawaban', 'gray'],
                },
            ], $revision->additions()),
            'removed' => array_map($name, $revision->removals()),
            'mentor' => $mentorTo !== null ? MentorChoice::name(User::query()->with('staffProfile')->find($mentorTo)) ?? 'Guru lain' : null,
            'reason' => $revision->reason,
            'ready' => $revision->ready_at !== null,
            'sentOn' => $revision->created_at?->locale('id')->translatedFormat('j M Y, H.i'),
        ];
    }

    /** The latest revision that ended, as one line (approved, rejected with its note, or cancelled). */
    private function lastDecided(Registration $registration): ?array
    {
        $revision = $registration->revisions()->where('status', '!=', RevisionStatus::Pending->value)->with('decider.roles')->latest('decided_at')->latest('id')->first();

        if ($revision === null) {
            return null;
        }

        $on = $revision->decided_at?->locale('id')->translatedFormat('j M Y');

        return match ($revision->status) {
            RevisionStatus::Approved => ['tone' => 'success', 'text' => 'Revisi terakhir disetujui '.StaffRoleLabel::forDecision($revision->decider).($on ? ' pada '.$on : '').'.'],
            RevisionStatus::Rejected => ['tone' => 'warning', 'text' => 'Revisi terakhir ditolak '.StaffRoleLabel::forDecision($revision->decider).($on ? ' pada '.$on : '').'. Alasan: '.$revision->note],
            default => ['tone' => 'info', 'text' => 'Revisi terakhir dibatalkan'.($on ? ' pada '.$on : '').'.'],
        };
    }

    /** @param array{added: list<string>, removed: list<string>, mentor_changed: bool, status: RegistrationStatus, ready: bool} $result */
    private static function changedText(array $result): string
    {
        return 'Pendaftaran diubah.'.match (true) {
            $result['ready'] => ' Pendaftaran diteruskan untuk persetujuan.',
            $result['added'] !== [] && $result['status'] === RegistrationStatus::MenungguKonfirmasi => ' Pendaftaran menunggu konfirmasi anggota baru.',
            default => '',
        };
    }

    // Helpers ----------------------------------------------------------------------------------

    private function mayChange(Registration $registration): bool
    {
        return Gate::allows('change', $registration) || Gate::allows('requestRevision', $registration);
    }

    private function refusal(Registration $registration): string
    {
        if ($registration->pendingRevision()->exists()) {
            return RevisionRules::MSG_PENDING;
        }

        return RevisionRules::closedReason($registration) ?? 'Pendaftaran ini tidak bisa diubah sekarang.';
    }

    private function fillForm(Registration $registration): void
    {
        $this->reset('tambah', 'keluarkan', 'alasan', 'q', 'hasil', 'searchNote');
        $this->guru = (string) ($registration->mentor_id ?? '');
        $this->openedOn = $this->snapshot($registration);
    }

    /** @return array{status: string, members: list<string>, mentor: ?int} */
    private function snapshot(Registration $registration): array
    {
        $members = $registration->members()->whereNull('declined_at')->orderBy('student_id')->pluck('student_id')
            ->map(fn (mixed $id): string => (string) $id)->values()->all();

        return [
            'status' => $registration->status->value,
            'members' => $members,
            'mentor' => $registration->mentor_id !== null ? (int) $registration->mentor_id : null,
        ];
    }

    private function close(): void
    {
        $this->openedOn = null;
        $this->dispatch('sth-modal-close', id: self::DIALOG);
    }

    /** Every refusal where the form shows it; the registration itself (approved, closed) closes the form with a toast. */
    private function showRefusal(ValidationException $e): void
    {
        $errors = $e->errors();

        if (isset($errors['registration'])) {
            $this->close();
            $this->dispatch('sth-toast', message: (string) $errors['registration'][0].' Halaman sudah diperbarui.', tone: 'warning');
            $this->dispatch(self::CHANGED);

            return;
        }

        foreach ($errors as $key => $messages) {
            $field = match ($key) {
                'mentor' => 'guru',
                'reason' => 'alasan',
                'members' => 'tambah',
                default => 'changes',
            };

            foreach ($messages as $message) {
                $this->addError($field, (string) $message);
            }
        }
    }

    private function registration(): Registration
    {
        $registration = Registration::query()->find($this->registrationId);
        abort_if($registration === null || ! RevisionRules::leads($this->user(), $registration), 404);

        return $registration;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
