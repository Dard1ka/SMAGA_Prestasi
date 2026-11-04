<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\EnableAccount;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Archive\ArchiveDates;
use App\Actions\Archive\UnarchiveStudent;
use App\Enums\Permission;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Ubah status" of student accounts (SV5, the lead 2026-11-04): ONE dialog for Admin, rendered once inside Admin › Kelas
 * (rows of "Lihat siswa", one or many ticked) and inside Status aktivasi (Admin only, one row). The host page checks the
 * ids against the rows it showed and sends OPEN_EVENT to this component; this component checks them again (Admin only:
 * 403 for others; every id a student account: a staff or unknown id answers 404) and keeps them in #[Locked] $userIds.
 *
 * Targets (offered only when they apply to at least one picked student; K9 state of AccountControls::state()):
 * - "Aktif kembali": arsip → UnarchiveStudent (a new archive date), nonaktif → EnableAccount (a new archive date only
 *   when that student's date passed), dibekukan → UnfreezeAccount, kedaluwarsa → ReopenActivationWindow; an account
 *   that is still dibekukan or kedaluwarsa after its step gets the next step too, so it ends able to log in;
 * - "Nonaktif": DisableAccount (not for students already nonaktif);
 * - "Arsip (alumni)": ArchiveAccount (not for students already archived).
 * One reason (REASON_MIN–REASON_MAX characters, so it also fits "Buka ulang"), the date field only when the chosen
 * target needs it for at least one picked student. "Simpan" asks for a password confirmation from the last 15 minutes
 * (SECURITY §3m-9), then runs the Action of each student in its own transaction: every student is read again at that
 * moment and skipped when the target no longer applies (stale screens: never a 403/404 page). One toast says how many
 * were changed, skipped or refused; DONE_EVENT tells the host to redraw its lists and counts.
 *
 * D-128 pattern: save() acts only on what open() stored and is a silent no-op without it; the dialog never uses a
 * native confirm.
 */
final class UbahStatusSiswa extends Component
{
    use RequiresRecentPassword;

    public const DIALOG = 'ubah-status-siswa';

    /** Host → this component: `ids` (student user ids) and an optional `target`. */
    public const OPEN_EVENT = 'ubah-status-siswa-buka';

    /** This component → host: something may have changed, redraw. */
    public const DONE_EVENT = 'ubah-status-siswa-selesai';

    public const TARGET_ACTIVE = 'aktif';

    public const TARGET_DISABLED = 'nonaktif';

    public const TARGET_ARCHIVED = 'arsip';

    /** @var array<string, string> */
    public const TARGETS = [
        self::TARGET_ACTIVE => 'Aktif kembali',
        self::TARGET_DISABLED => 'Nonaktif',
        self::TARGET_ARCHIVED => 'Arsip (alumni)',
    ];

    /** K9 states whose "Aktif kembali" does something: the rows that preselect it and read "Aktifkan lagi". */
    public const RESTORABLE = [
        AccountControls::STATE_ARCHIVED,
        AccountControls::STATE_DISABLED,
        AccountControls::STATE_FROZEN,
        AccountControls::STATE_EXPIRED,
    ];

    /** Upper bound of one selection (the student list of Admin › Kelas shows at most Kelas::STUDENTS_MAX rows). */
    public const MAX_STUDENTS = 400;

    public const REASON_MIN = 10;

    public const REASON_MAX = 500;

    /** Why the students the chosen target does not apply to are left alone. */
    public const SKIP_NOTES = [
        self::TARGET_ACTIVE => 'sudah aktif atau masih dalam masa aktivasi',
        self::TARGET_DISABLED => 'sudah nonaktif',
        self::TARGET_ARCHIVED => 'sudah diarsipkan',
    ];

    private const STEP_UNARCHIVE = 'unarchive';

    private const STEP_ENABLE = 'enable';

    private const STEP_UNFREEZE = 'unfreeze';

    private const STEP_REOPEN = 'reopen';

    private const STEP_DISABLE = 'disable';

    private const STEP_ARCHIVE = 'archive';

    /** @var list<int> the student accounts open() asked about; empty = no question */
    #[Locked]
    public array $userIds = [];

    /** Page to come back to after the password confirmation (set by the host at mount). */
    #[Locked]
    public string $returnTo = '';

    public string $statusTarget = '';

    public string $statusReason = '';

    public string $statusArchiveDate = '';

    public function mount(?string $returnTo = null): void
    {
        Gate::authorize(Permission::UserArchive->value);
        $this->returnTo = $returnTo ?? url()->current();
    }

    /** Whether "Aktif kembali" does something for an account in this K9 state. */
    public static function restorable(string $state): bool
    {
        return in_array($state, self::RESTORABLE, true);
    }

    /**
     * Ask step: the dialog for these student accounts, with `$target` preselected when it applies to at least one of
     * them; without it "Aktif kembali" is preselected when every picked student is restorable, or the only target that
     * applies.
     *
     * @param  array<int|string, mixed>  $ids
     */
    #[On(self::OPEN_EVENT)]
    public function open(array $ids = [], ?string $target = null): void
    {
        Gate::authorize(Permission::UserArchive->value);
        $this->forget();

        $userIds = [];

        foreach ($ids as $id) {
            abort_unless(is_int($id) || (is_string($id) && ctype_digit($id)), 404);
            $userIds[] = (int) $id;
        }

        $userIds = array_values(array_unique($userIds));
        abort_if($userIds === [] || count($userIds) > self::MAX_STUDENTS, 404);

        $students = User::query()->whereKey($userIds)->whereHas('student')->get();
        abort_unless($students->count() === count($userIds), 404);

        foreach ($students as $student) {
            Gate::authorize('archive', $student);
        }

        $this->userIds = $userIds;
        $states = $students->map(fn (User $user): string => AccountControls::state($user));
        $applies = fn (string $key): bool => $states->contains(fn (string $state): bool => self::step($state, $key) !== null);
        $offered = array_values(array_filter(array_keys(self::TARGETS), $applies));

        $this->statusTarget = match (true) {
            $target !== null && in_array($target, $offered, true) => $target,
            $states->every(fn (string $state): bool => self::restorable($state)) => self::TARGET_ACTIVE,
            count($offered) === 1 => $offered[0],
            default => '',
        };

        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    public function updatedStatusTarget(): void
    {
        $this->resetErrorBag(['statusTarget', 'statusArchiveDate']);
    }

    /** The dialog closed (Batal, X, Esc, a click outside): forget the question. */
    public function forget(): void
    {
        $this->reset('userIds', 'statusTarget', 'statusReason', 'statusArchiveDate');
        $this->resetErrorBag();
    }

    /**
     * "Simpan". Without a question asked by open() there is nothing to confirm: it returns without a word, so a second
     * click after the change does nothing.
     */
    public function save(): void
    {
        if ($this->userIds === []) {
            return;
        }

        Gate::authorize(Permission::UserArchive->value);

        $target = array_key_exists($this->statusTarget, self::TARGETS) ? $this->statusTarget : null;
        $students = $this->students();

        foreach ($students as $student) {
            Gate::authorize('archive', $student);
        }

        $plan = $target === null
            ? collect()
            : $students->filter(fn (User $user): bool => self::step(AccountControls::state($user), $target) !== null);

        // Every picked student changed meanwhile (another tab, the nightly job): nothing left to ask a password for.
        if ($target !== null && $plan->isEmpty()) {
            $picked = count($this->userIds);
            $this->finish();
            $this->dispatch('sth-toast', message: self::resultMessage(0, $picked, 0, null).' Daftar sudah diperbarui.', tone: 'warning');

            return;
        }

        $date = $this->validated($plan, $target);

        if ($target === null) {
            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $reason = trim($this->statusReason);
        $changed = 0;
        $skipped = 0;
        $failed = 0;
        $firstFailure = null;

        foreach ($this->userIds as $id) {
            /** @var User|null $user */
            $user = User::query()->whereHas('student')->with('student')->find($id);

            if ($user === null) {
                $skipped++;

                continue;
            }

            Gate::authorize('archive', $user);

            try {
                if (! $this->apply($user, $target, $reason, $date)) {
                    $skipped++;

                    continue;
                }

                $changed++;
            } catch (ValidationException $e) {
                $failed++;
                $firstFailure ??= (string) (collect($e->errors())->flatten()->first() ?? 'Akun tidak bisa diubah.');
            } catch (AuthorizationException) {
                // The state changed between the read above and the Action's own check (a race): left alone.
                $skipped++;
            }
        }

        $this->finish();
        $this->dispatch('sth-toast', message: self::resultMessage($changed, $skipped, $failed, $firstFailure), tone: $failed > 0 || $changed === 0 ? 'warning' : 'success');
    }

    public function render(): View
    {
        // Every picked account deleted meanwhile: the dialog has nobody to show ("Simpan" then says so in a toast).
        $students = $this->userIds === [] ? collect() : $this->students();

        return view('livewire.admin.ubah-status-siswa', [
            'dialog' => $students->isEmpty() ? null : $this->dialog($students),
            'minDate' => CarbonImmutable::now(config('app.timezone'))->addDay()->toDateString(),
        ]);
    }

    /**
     * The step "$target" takes for an account in K9 state "$state", or null when it does not apply.
     */
    public static function step(string $state, string $target): ?string
    {
        return match ($target) {
            self::TARGET_ACTIVE => match ($state) {
                AccountControls::STATE_ARCHIVED => self::STEP_UNARCHIVE,
                AccountControls::STATE_DISABLED => self::STEP_ENABLE,
                AccountControls::STATE_FROZEN => self::STEP_UNFREEZE,
                AccountControls::STATE_EXPIRED => self::STEP_REOPEN,
                default => null,
            },
            self::TARGET_DISABLED => $state === AccountControls::STATE_DISABLED ? null : self::STEP_DISABLE,
            self::TARGET_ARCHIVED => $state === AccountControls::STATE_ARCHIVED ? null : self::STEP_ARCHIVE,
            default => null,
        };
    }

    /** The one toast after "Simpan". */
    public static function resultMessage(int $changed, int $skipped, int $failed, ?string $firstFailure): string
    {
        if ($changed === 0 && $failed === 0) {
            return 'Tidak ada akun yang diubah. '.$skipped.' dilewati karena statusnya sudah berubah.';
        }

        $message = $changed.' akun diubah.';

        if ($failed > 0) {
            $message .= ' '.$failed.' gagal: '.rtrim((string) $firstFailure, '.').'.';
        }

        if ($skipped > 0) {
            $message .= ' '.$skipped.' dilewati.';
        }

        return $message;
    }

    /** Close the dialog, forget the question and tell the host to redraw. */
    private function finish(): void
    {
        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch(self::DONE_EVENT);
    }

    /**
     * Checks reason, target and (when the plan needs one) the new archive date in one round, so every error shows at
     * once. Returns the date for the steps that need it, else null.
     *
     * @param  Collection<int, User>  $plan
     *
     * @throws ValidationException
     */
    private function validated(Collection $plan, ?string $target): ?CarbonImmutable
    {
        $errors = $target === null ? ['statusTarget' => 'Pilih status baru.'] : [];
        $reason = trim($this->statusReason);

        if ($reason === '') {
            $errors['statusReason'] = 'Isi alasannya.';
        } elseif (mb_strlen($reason) < self::REASON_MIN) {
            $errors['statusReason'] = 'Alasan minimal '.self::REASON_MIN.' karakter.';
        } elseif (mb_strlen($reason) > self::REASON_MAX) {
            $errors['statusReason'] = 'Alasan maksimal '.self::REASON_MAX.' karakter.';
        }

        $date = null;

        if ($target !== null && $plan->contains(fn (User $user): bool => self::needsDate($user, $target))) {
            if (trim($this->statusArchiveDate) === '') {
                $errors['statusArchiveDate'] = 'Isi tanggal arsip otomatis baru.';
            } else {
                try {
                    $date = ArchiveDates::validated($this->statusArchiveDate, 'statusArchiveDate');
                } catch (ValidationException $e) {
                    $errors['statusArchiveDate'] = (string) collect($e->errors())->flatten()->first();
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $date;
    }

    /**
     * Runs the step of one student, read again just now. False when the target no longer applies (skipped). For
     * "Aktif kembali" an account that is still dibekukan or kedaluwarsa afterwards gets the next step too (an archived
     * account that never activated comes back with an expired window), so it ends able to log in.
     *
     * @throws ValidationException
     */
    private function apply(User $user, string $target, string $reason, ?CarbonImmutable $date): bool
    {
        $step = self::step(AccountControls::state($user), $target);

        if ($step === null) {
            return false;
        }

        $this->run($step, $user, $reason, $date);

        if ($target !== self::TARGET_ACTIVE) {
            return true;
        }

        for ($i = 0; $i < 2; $i++) {
            $user = User::query()->with('student')->findOrFail($user->getKey());
            $next = self::step(AccountControls::state($user), self::TARGET_ACTIVE);

            if (! in_array($next, [self::STEP_UNFREEZE, self::STEP_REOPEN], true)) {
                break;
            }

            $this->run($next, $user, $reason, $date);
        }

        return true;
    }

    /** @throws ValidationException */
    private function run(string $step, User $user, string $reason, ?CarbonImmutable $date): void
    {
        $actor = $this->actor();

        match ($step) {
            self::STEP_UNARCHIVE => app(UnarchiveStudent::class)->handle($actor, $user, $date ?? '', $reason),
            self::STEP_ENABLE => app(EnableAccount::class)->handle($actor, $user, $reason, EnableAccount::archiveDatePassed($user->student) ? $date : null),
            self::STEP_UNFREEZE => app(UnfreezeAccount::class)->handle($actor, $user, $reason),
            self::STEP_REOPEN => app(ReopenActivationWindow::class)->handle($actor, $user, $reason),
            self::STEP_DISABLE => app(DisableAccount::class)->handle($actor, $user, $reason),
            self::STEP_ARCHIVE => app(ArchiveAccount::class)->handle($actor, $user, $reason),
        };
    }

    /** Whether the step of "$target" for this account needs the new archive date. */
    private static function needsDate(User $user, string $target): bool
    {
        return match (self::step(AccountControls::state($user), $target)) {
            self::STEP_UNARCHIVE => true,
            self::STEP_ENABLE => EnableAccount::archiveDatePassed($user->student),
            default => false,
        };
    }

    /**
     * The picked student accounts as they are now, by name (a deleted or no longer student account drops out).
     *
     * @return Collection<int, User>
     */
    private function students(): Collection
    {
        return User::query()
            ->whereKey($this->userIds)
            ->whereHas('student')
            ->with('student.classroom:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * View model of the dialog.
     *
     * @param  Collection<int, User>  $students
     * @return array{count: int, name: ?string, classroom: ?string, names: string, targets: array<string, string>, submit: string, danger: bool, chosen: ?array{changes: int, skipped: int, summary: string, effects: list<string>, needsDate: int, dateHint: string}}
     */
    private function dialog(Collection $students): array
    {
        $states = $students->mapWithKeys(fn (User $user): array => [(int) $user->getKey() => AccountControls::state($user)]);
        $targets = [];

        foreach (self::TARGETS as $key => $label) {
            if ($states->contains(fn (string $state): bool => self::step($state, $key) !== null)) {
                $targets[$key] = $label;
            }
        }

        $target = $this->statusTarget;
        $chosen = null;

        if (array_key_exists($target, self::TARGETS)) {
            $plan = $students->filter(fn (User $user): bool => self::step($states[(int) $user->getKey()], $target) !== null);
            $needsDate = $plan->filter(fn (User $user): bool => self::needsDate($user, $target))->count();

            $skipped = $students->count() - $plan->count();
            $chosen = [
                'changes' => $plan->count(),
                'skipped' => $skipped,
                'summary' => $plan->count().' akun akan diubah.'.($skipped > 0 ? ' '.$skipped.' dilewati karena '.self::SKIP_NOTES[$target].'.' : ''),
                'effects' => self::effects($target, $plan, $states),
                'needsDate' => $needsDate,
                'dateHint' => $students->count() === 1
                    ? 'Harus setelah hari ini. Tanpa tanggal baru, akun ini diarsipkan lagi malam ini.'
                    : 'Untuk '.$needsDate.' siswa yang diarsipkan atau tanggal arsipnya sudah lewat. Harus setelah hari ini.',
            ];
        }

        $first = $students->first();
        $names = $students->take(3)->map(fn (User $user): string => (string) $user->name)->implode(', ');

        if ($students->count() > 3) {
            $names .= ', dan '.($students->count() - 3).' lainnya';
        }

        return [
            'count' => $students->count(),
            'name' => $students->count() === 1 ? (string) $first?->name : null,
            'classroom' => $students->count() === 1 ? $first?->student?->classroom?->name : null,
            'names' => $names,
            'targets' => $targets,
            'submit' => match ($target) {
                self::TARGET_ACTIVE => 'Aktifkan lagi',
                self::TARGET_DISABLED => 'Nonaktifkan',
                self::TARGET_ARCHIVED => 'Arsipkan',
                default => 'Simpan',
            },
            'danger' => in_array($target, [self::TARGET_DISABLED, self::TARGET_ARCHIVED], true),
            'chosen' => $chosen,
        ];
    }

    /**
     * What the chosen target does, one sentence per kind of account.
     *
     * @param  Collection<int, User>  $plan
     * @param  Collection<int, string>  $states
     * @return list<string>
     */
    private static function effects(string $target, Collection $plan, Collection $states): array
    {
        if ($target === self::TARGET_DISABLED) {
            return ['Akun nonaktif tidak bisa login dan semua sesinya diakhiri. Prestasinya tetap tersimpan.'];
        }

        if ($target === self::TARGET_ARCHIVED) {
            return ['Akun alumni tidak bisa login dan semua sesinya diakhiri. Prestasinya tetap tampil di peringkat.'];
        }

        $count = fn (string $state): int => $plan->filter(fn (User $user): bool => $states[(int) $user->getKey()] === $state)->count();
        $lines = array_values(array_filter([
            ($n = $count(AccountControls::STATE_ARCHIVED)) > 0 ? $n.' akun diarsipkan dipulihkan dengan tanggal arsip otomatis baru.' : null,
            ($n = $count(AccountControls::STATE_DISABLED)) > 0 ? $n.' akun nonaktif diaktifkan lagi.' : null,
            ($n = $count(AccountControls::STATE_FROZEN)) > 0 ? $n.' akun dibekukan dibuka pembekuannya.' : null,
            ($n = $count(AccountControls::STATE_EXPIRED)) > 0 ? $n.' akun kedaluwarsa mendapat 72 jam lagi untuk aktivasi.' : null,
        ]));

        if ($plan->contains(fn (User $user): bool => (bool) $user->must_change_password)) {
            $lines[] = 'Akun yang belum selesai aktivasi tetap harus aktivasi. Kata sandi awal atau sementaranya berlaku lagi, paling sedikit 72 jam dari sekarang.';
        }

        if ($plan->contains(fn (User $user): bool => AccountControls::frozenReason($user) === AccountControls::FROZEN_NOT_ME)) {
            $lines[] = 'Akun yang dibekukan lewat laporan "Bukan saya" sebaiknya di-reset kata sandi dan emailnya di Pengguna & peran.';
        }

        return $lines;
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
