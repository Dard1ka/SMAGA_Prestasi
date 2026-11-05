<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\AccountControls;
use App\Actions\Students\UpdateStudentData;
use App\Enums\LetterType;
use App\Enums\Permission;
use App\Enums\UserStatus;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\Classroom;
use App\Models\Letter;
use App\Models\Student;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;

/**
 * SV6, D-187: the shared "Ubah data siswa" dialog of Admin, Kesiswaan and Tata Usaha (`student.update`). Nama lengkap,
 * NIS, NISN, Tanggal lahir (DD-MM-YYYY) and Kelas (active classes not in the Sampah, grouped by tingkat) of ONE student,
 * saved by App\Actions\Students\UpdateStudentData (the rules live there). A read-only line shows the status, the masked
 * email and "Angkatan N · arsip otomatis <bulan tahun>"; the effects box lists only what the changed fields cause.
 *
 * Contract every host page keeps:
 * - render it once per page, only for an account that holds `student.update`:
 *   `<livewire:akun.ubah-data-siswa :key="'ubah-data-siswa'" />`
 * - open it for ONE student the host has checked against the rows it showed:
 *   `$this->dispatch(UbahDataSiswa::OPEN_EVENT, userId: (int) $user->getKey());`
 * - listen for DONE_EVENT (`#[On(UbahDataSiswa::DONE_EVENT)]`) to redraw its own list after a save.
 *
 * open() checks `student.update` and StudentPolicy::update again (a staff or unknown account id answers 404, a student
 * moved to the Sampah meanwhile a warning toast) and asks for a password confirmation from the last 15 minutes BEFORE
 * the dialog opens (the password page never throws typed values away); save() checks it again. The student id and the
 * snapshot of what the dialog showed are #[Locked]. D-128: save() acts only on what open() stored and is a silent no-op
 * without it; Batal, X, Esc and a click outside forget the question (forget()).
 *
 * Stale screens (CLAUDE.md §6): the data changed meanwhile → warning toast and the fields reloaded; the student moved to
 * the Sampah meanwhile → the dialog closes with a warning toast; nothing typed differs from the data → info toast "Tidak
 * ada data yang diubah.". After a save DONE_EVENT tells the host to redraw. Nobody is notified.
 */
final class UbahDataSiswa extends Component
{
    use RequiresRecentPassword;

    /** Host → dialog: open for one student account (named argument `userId`, the users.id). */
    public const OPEN_EVENT = 'ubah-data-siswa-buka';

    /** Dialog → hosts: a student's data changed; redraw lists and counts. */
    public const DONE_EVENT = 'ubah-data-siswa-selesai';

    /** The modal id of the dialog (one per page). */
    public const DIALOG = 'ubah-data-siswa';

    public const MSG_SAVED = 'Data siswa disimpan.';

    public const MSG_NOTHING = 'Tidak ada data yang diubah.';

    // Lines of the effects box, one per consequence of a changed field.
    public const EFFECT_NISN = 'Siswa masuk dengan NISN baru. Beri tahu siswanya.';

    public const EFFECT_PASSWORD = 'Kata sandi awal ikut tanggal lahir baru.';

    public const EFFECT_EXCEL = 'Samakan juga di file Excel sekolah agar import berikutnya cocok.';

    public const EFFECT_LETTER = 'Surat yang sudah terbit tetap; pakai Koreksi.';

    /** Error keys of UpdateStudentData → the fields of this dialog. */
    private const FIELD_KEYS = [
        'name' => 'dataName',
        'nis' => 'dataNis',
        'nisn' => 'dataNisn',
        'birth_date' => 'dataBirthDate',
        'classroom_id' => 'dataClassroomId',
    ];

    /** The student account open() asked about; null = no question. */
    #[Locked]
    public ?int $userId = null;

    /** @var array<string, string|null> UpdateStudentData::snapshot() of what the dialog showed */
    #[Locked]
    public array $shown = [];

    /** The stored password is still the birth-date password (UpdateStudentData::onBirthDatePassword()). */
    #[Locked]
    public bool $onInitialPassword = false;

    /** A current student letter (Surat tugas siswa) names this student. */
    #[Locked]
    public bool $hasLetter = false;

    /** Page to come back to after the password confirmation. */
    #[Locked]
    public string $returnTo = '';

    /** The audit `source`: the screen the dialog stands on (UpdateStudentData::SOURCES). */
    #[Locked]
    public string $source = UpdateStudentData::SOURCE_OTHER;

    public string $dataName = '';

    public string $dataNis = '';

    public string $dataNisn = '';

    public string $dataBirthDate = '';

    public string $dataClassroomId = '';

    public function mount(?string $returnTo = null, ?string $source = null): void
    {
        Gate::authorize(Permission::StudentUpdate->value);

        $this->returnTo = $returnTo ?? (Livewire::isLivewireRequest() ? Livewire::originalUrl() : url()->full());
        $this->source = in_array($source, UpdateStudentData::SOURCES, true) ? (string) $source : self::sourceFor(self::hostRoute());
    }

    /** Ask step: the dialog for one student account (CLAUDE.md §7, D-128). */
    #[On(self::OPEN_EVENT)]
    public function open(int $userId): void
    {
        Gate::authorize(Permission::StudentUpdate->value);
        $this->forget();

        $account = self::account($userId);

        if ($account === null) {
            // A staff account never is a student here: 404. A student moved to the Sampah (or purged) meanwhile: a toast.
            abort_if(User::withTrashed()->whereKey($userId)->whereDoesntHave('student', fn (Builder $q) => $q->withTrashed())->exists(), 404);
            $this->gone();

            return;
        }

        /** @var Student $student */
        $student = $account->student;
        Gate::authorize('update', $student);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->userId = (int) $account->getKey();
        $this->showAccount($account);
        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    /** The dialog closed (Batal, X, Esc, a click outside): forget the question and the typed values. */
    public function forget(): void
    {
        $this->reset('userId', 'shown', 'onInitialPassword', 'hasLetter', 'dataName', 'dataNis', 'dataNisn', 'dataBirthDate', 'dataClassroomId');
        $this->resetErrorBag();
    }

    /**
     * "Simpan perubahan". Without a question asked by open() there is nothing to save: it returns without a word, so a
     * second click after the save does nothing.
     */
    public function save(UpdateStudentData $update): void
    {
        if ($this->userId === null) {
            return;
        }

        Gate::authorize(Permission::StudentUpdate->value);

        $account = self::account($this->userId);

        if ($account === null) {
            $this->gone();

            return;
        }

        Gate::authorize('update', $account->student);

        if (UpdateStudentData::snapshot($account) !== $this->shown) {
            $this->changedMeanwhile($account);

            return;
        }

        $input = $this->input();
        $errors = $update->errors($account, $input);

        if ($errors !== []) {
            throw ValidationException::withMessages(self::fieldErrors($errors));
        }

        $this->resetErrorBag();

        if ($update->changedFields($account, $input) === []) {
            $this->dispatch('sth-toast', message: self::MSG_NOTHING, tone: 'info');

            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $update->handle($this->actor(), $account, $input, $this->source, $this->shown);
        } catch (ValidationException $e) {
            $messages = array_map(fn (array $m): string => (string) ($m[0] ?? ''), $e->errors());
            $about = $messages[UpdateStudentData::KEY_STUDENT] ?? null;

            if ($about === UpdateStudentData::MSG_GONE) {
                $this->gone();

                return;
            }

            if ($about !== null) {
                $fresh = self::account($this->userId);
                $fresh === null ? $this->gone() : $this->changedMeanwhile($fresh);

                return;
            }

            throw ValidationException::withMessages(self::fieldErrors($messages));
        }

        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch(self::DONE_EVENT);
        $this->dispatch('sth-toast', message: self::MSG_SAVED, tone: 'success');
    }

    public function render(UpdateStudentData $update): View
    {
        $account = $this->userId === null ? null : self::account($this->userId);

        return view('livewire.akun.ubah-data-siswa', [
            'dialog' => $account === null ? null : $this->dialog($account, $update),
        ]);
    }

    /** The audit source of a host route (UpdateStudentData::SOURCES). */
    public static function sourceFor(?string $route): string
    {
        $route = (string) $route;

        return match (true) {
            $route === '' => UpdateStudentData::SOURCE_OTHER,
            str_starts_with($route, 'admin.kelas') => 'kelas',
            str_contains($route, '.aktivasi') => 'aktivasi',
            in_array($route, ['kesiswaan.import', 'admin.import'], true) => 'import',
            str_starts_with($route, 'admin.pengguna') || str_starts_with($route, 'admin.pengaturan') => 'pengguna',
            str_starts_with($route, 'tu.siswa') => 'data_siswa',
            str_starts_with($route, 'tu.') => 'surat',
            default => UpdateStudentData::SOURCE_OTHER,
        };
    }

    /** Whether a current student letter (not revoked) names this student as a confirmed member. */
    public static function hasIssuedLetter(Student $student): bool
    {
        return Letter::query()
            ->current()
            ->ofType(LetterType::Siswa)
            ->whereHas('registration', fn (Builder $r) => $r->withTrashed()->whereHas('members', fn (Builder $m) => $m
                ->where('student_id', $student->getKey())
                ->whereNotNull('confirmed_at')
                ->whereNull('declined_at')))
            ->exists();
    }

    /**
     * View model of the open dialog.
     *
     * @return array{state: string, stateLabel: string, stateTone: string, email: ?string, cohort: string, archived: bool, profileConfirmed: bool, classes: array<string, array<string, string>>, effects: list<string>}
     */
    private function dialog(User $account, UpdateStudentData $update): array
    {
        /** @var Student $student */
        $student = $account->student;
        $state = AccountControls::state($account);
        $archiveAt = $student->archive_at;

        return [
            'state' => $state,
            'stateLabel' => AccountControls::stateLabel($state),
            'stateTone' => AccountControls::stateTone($state),
            'email' => AccountControls::maskEmail($account->email),
            'cohort' => ($student->grade12_start_date !== null ? 'Angkatan '.($student->grade12_start_date->year - 2) : 'Angkatan –')
                .' · arsip otomatis '.($archiveAt !== null ? self::month($archiveAt) : '–'),
            'archived' => $account->status === UserStatus::Archived,
            'profileConfirmed' => $student->profile_confirmed_at !== null,
            'classes' => self::classOptions($student),
            'effects' => $this->effects($student, $update),
        ];
    }

    /**
     * One line per consequence of what was typed, compared with what the dialog showed. Only valid changes count: an
     * invalid value gets its error on "Simpan perubahan".
     *
     * @return list<string>
     */
    private function effects(Student $student, UpdateStudentData $update): array
    {
        $digits = fn (string $value): string => (string) preg_replace('/\s+/u', '', $value);
        $nisChanged = $digits($this->dataNis) !== (string) ($this->shown['nis'] ?? '');
        $nisnChanged = $digits($this->dataNisn) !== (string) ($this->shown['nisn'] ?? '');
        $nameChanged = UpdateStudentData::squish($this->dataName) !== (string) ($this->shown['name'] ?? '');
        $classChanged = $this->dataClassroomId !== (string) ($this->shown['classroom_id'] ?? '');
        $birthDate = UpdateStudentData::parseDate($this->dataBirthDate);
        $birthChanged = $birthDate !== null && $birthDate->format('Y-m-d') !== ($this->shown['birth_date'] ?? null);
        $archiveAt = $classChanged ? $update->newArchiveDate($student, $this->dataClassroomId) : null;
        $today = CarbonImmutable::now('Asia/Jakarta')->startOfDay();

        return array_values(array_filter([
            $nisnChanged ? self::EFFECT_NISN : null,
            $birthChanged && $this->onInitialPassword ? self::EFFECT_PASSWORD : null,
            $archiveAt !== null && $archiveAt->greaterThan($today) ? 'Tanggal arsip otomatis menjadi '.self::month($archiveAt).'.' : null,
            $nisChanged || $nisnChanged ? self::EFFECT_EXCEL : null,
            $this->hasLetter && ($nameChanged || $nisChanged || $classChanged) ? self::EFFECT_LETTER : null,
        ]));
    }

    /**
     * Active classes not in the Sampah, grouped by tingkat ("Kelas X" => [id => name]); the student's own class stays in
     * the list even when it is no longer active, so the field shows it.
     *
     * @return array<string, array<string, string>>
     */
    private static function classOptions(Student $student): array
    {
        $classes = Classroom::query()->active()->ordered()->get(['id', 'name', 'level']);
        $own = $student->classroom_id !== null && ! $classes->contains('id', $student->classroom_id)
            ? Classroom::withTrashed()->find($student->classroom_id, ['id', 'name', 'level'])
            : null;

        if ($own !== null) {
            $classes->push($own);
        }

        $groups = [];

        foreach ($classes as $classroom) {
            $label = match ((int) $classroom->level) {
                10 => 'Kelas X',
                11 => 'Kelas XI',
                12 => 'Kelas XII',
                default => 'Kelas lain',
            };
            $groups[$label][(string) $classroom->getKey()] = $classroom->is($own) ? $classroom->name.' (tidak aktif)' : (string) $classroom->name;
        }

        return $groups;
    }

    /** The dialog's fields and snapshot from the account as it is now. */
    private function showAccount(User $account): void
    {
        /** @var Student $student */
        $student = $account->student;

        $this->shown = UpdateStudentData::snapshot($account);
        $this->dataName = (string) $account->name;
        $this->dataNis = (string) $student->nis;
        $this->dataNisn = (string) $student->nisn;
        $this->dataBirthDate = $student->birth_date?->format('d-m-Y') ?? '';
        $this->dataClassroomId = (string) $student->classroom_id;
        $this->onInitialPassword = UpdateStudentData::onBirthDatePassword($account, $student);
        $this->hasLetter = self::hasIssuedLetter($student);
    }

    /** The data changed meanwhile (another tab, an import): the fields show it now, the dialog stays open. */
    private function changedMeanwhile(User $account): void
    {
        $this->resetErrorBag();
        $this->showAccount($account);
        $this->dispatch(self::DONE_EVENT);
        $this->dispatch('sth-toast', message: UpdateStudentData::MSG_CHANGED, tone: 'warning');
    }

    /** The student was moved to the Sampah (or purged) meanwhile: close, say so, let the host redraw. */
    private function gone(): void
    {
        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch(self::DONE_EVENT);
        $this->dispatch('sth-toast', message: UpdateStudentData::MSG_GONE, tone: 'warning');
    }

    /** @return array<string, string> */
    private function input(): array
    {
        return [
            'name' => $this->dataName,
            'nis' => $this->dataNis,
            'nisn' => $this->dataNisn,
            'birth_date' => $this->dataBirthDate,
            'classroom_id' => $this->dataClassroomId,
        ];
    }

    /**
     * @param  array<string, string>  $errors  keys of UpdateStudentData
     * @return array<string, string> keys of this dialog
     */
    private static function fieldErrors(array $errors): array
    {
        $mapped = [];

        foreach ($errors as $key => $message) {
            $mapped[self::FIELD_KEYS[$key] ?? 'dataName'] ??= $message;
        }

        return $mapped;
    }

    /** A student account that is not in the Sampah, with its student row (null otherwise). */
    private static function account(int $userId): ?User
    {
        /** @var User|null $account */
        $account = User::query()->whereKey($userId)->whereHas('student')->with('student')->first();

        return $account?->student === null ? null : $account;
    }

    private static function month(\DateTimeInterface $date): string
    {
        return CarbonImmutable::parse($date->format('Y-m-d'))->locale('id')->translatedFormat('F Y');
    }

    /** The route of the page the dialog stands on (also during a Livewire request). */
    private static function hostRoute(): ?string
    {
        if (! Livewire::isLivewireRequest()) {
            return request()->route()?->getName();
        }

        try {
            return app('router')->getRoutes()->match(Request::create(Livewire::originalUrl()))->getName();
        } catch (Throwable) {
            return null;
        }
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    /** Back from the password confirmation (RequiresRecentPassword): the host page. */
    protected function returnUrl(): string
    {
        return $this->returnTo;
    }
}
