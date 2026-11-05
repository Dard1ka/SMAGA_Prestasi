<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivateStudentAccount;
use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\EnableAccount;
use App\Actions\Accounts\ExpireActivationWindow;
use App\Actions\Accounts\FreezeAccount;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Archive\ArchiveDates;
use App\Actions\Archive\UnarchiveStudent;
use App\Enums\UserStatus;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Ubah status" of student accounts (SV5, the lead 2026-11-04; every state since 2026-11-05): ONE dialog, rendered once
 * inside the Kelas page (rows of "Lihat siswa", one or many ticked) and inside Status aktivasi. Who: UserPolicy::
 * changeStudentStatus (`user.archive` or `student.status`: Admin, and since 2026-11-05 Kesiswaan and Tata Usaha; the
 * chained Actions accept `student.status` for a student account through StudentStatusAuthorization).
 * The host page checks the ids against the rows it showed and sends OPEN_EVENT to this component; this component checks
 * them again (403 without the right; every id a student account: a staff or unknown id answers 404) and keeps them in
 * #[Locked] $userIds.
 *
 * Targets = the six K9 states of AccountControls::state(), the tabs of Status aktivasi. A target is offered when at
 * least one picked student is in another state; a student already in it is skipped. Each student walks a chain of the
 * existing Actions until the account is in the target state (step(), apply()):
 * - first back to an account that can log in: arsip → UnarchiveStudent (a new archive date), nonaktif → EnableAccount
 *   (a new archive date only when that student's date passed), dibekukan → UnfreezeAccount;
 * - "Aktif": belum / kedaluwarsa → ActivateStudentAccount (the student still binds an email and replaces the password
 *   at the next login);
 * - "Belum aktivasi": aktif → ResetStudentAccount (email removed, the temporary password the Admin typed, D-167),
 *   kedaluwarsa → ReopenActivationWindow;
 * - "Kedaluwarsa": as "Belum aktivasi" for an active account, then ExpireActivationWindow;
 * - "Dibekukan": FreezeAccount; "Nonaktif": DisableAccount; "Diarsipkan": ArchiveAccount (one step from any state).
 * One reason (REASON_MIN–REASON_MAX characters, so it also fits "Buka ulang"); the date field only when the chosen
 * target needs it for at least one picked student; the field "Kata sandi sementara" only when at least one picked
 * student is reset (one value for all of them; it is never shown back, logged or audited, and forgotten after
 * "Simpan"). "Simpan" asks for a password confirmation from the last 15 minutes (SECURITY §3m-9), then changes each
 * student in its own transaction: every student is read again at that moment and skipped when it is already in the
 * target state (stale screens: never a 403/404 page); a refused step rolls back that student's whole chain. One toast
 * says how many were changed, skipped or refused; DONE_EVENT tells the host to redraw its lists and counts. Nobody is
 * notified.
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

    // A target is the K9 state the accounts end in.
    public const TARGET_PENDING = AccountControls::STATE_PENDING;

    public const TARGET_ACTIVE = AccountControls::STATE_ACTIVE;

    public const TARGET_EXPIRED = AccountControls::STATE_EXPIRED;

    public const TARGET_FROZEN = AccountControls::STATE_FROZEN;

    public const TARGET_DISABLED = AccountControls::STATE_DISABLED;

    public const TARGET_ARCHIVED = AccountControls::STATE_ARCHIVED;

    /** @var array<string, string> the order and the labels of Status aktivasi */
    public const TARGETS = [
        self::TARGET_PENDING => 'Belum aktivasi',
        self::TARGET_ACTIVE => 'Aktif',
        self::TARGET_EXPIRED => 'Kedaluwarsa',
        self::TARGET_FROZEN => 'Dibekukan',
        self::TARGET_DISABLED => 'Nonaktif',
        self::TARGET_ARCHIVED => 'Diarsipkan',
    ];

    /** K9 states whose row reads "Aktifkan lagi" and preselects "Aktif" (an account to bring back). */
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

    /** Why the students already in the chosen state are left alone. */
    public const SKIP_NOTES = [
        self::TARGET_PENDING => 'masih belum aktivasi',
        self::TARGET_ACTIVE => 'sudah aktif',
        self::TARGET_EXPIRED => 'sudah kedaluwarsa',
        self::TARGET_FROZEN => 'sudah dibekukan',
        self::TARGET_DISABLED => 'sudah nonaktif',
        self::TARGET_ARCHIVED => 'sudah diarsipkan',
    ];

    /** The longest chain (arsip + dibekukan + aktif → kedaluwarsa: unarchive, unfreeze, reset, expire) plus one. */
    private const MAX_STEPS = 5;

    private const STEP_UNARCHIVE = 'unarchive';

    private const STEP_ENABLE = 'enable';

    private const STEP_UNFREEZE = 'unfreeze';

    private const STEP_ACTIVATE = 'activate';

    private const STEP_RESET = 'reset';

    private const STEP_REOPEN = 'reopen';

    private const STEP_EXPIRE = 'expire';

    private const STEP_FREEZE = 'freeze';

    private const STEP_DISABLE = 'disable';

    private const STEP_ARCHIVE = 'archive';

    private const MSG_STUCK = 'Status akun tidak bisa diubah.';

    /** @var list<int> the student accounts open() asked about; empty = no question */
    #[Locked]
    public array $userIds = [];

    /** Page to come back to after the password confirmation (set by the host at mount). */
    #[Locked]
    public string $returnTo = '';

    public string $statusTarget = '';

    public string $statusReason = '';

    public string $statusArchiveDate = '';

    /** The temporary password of the students "Simpan" resets (ResetStudentAccount); never shown back. */
    public string $statusTemporaryPassword = '';

    public function mount(?string $returnTo = null): void
    {
        Gate::authorize('changeStudentStatus', User::class);
        $this->returnTo = $returnTo ?? url()->current();
    }

    /** Whether a row in this K9 state reads "Aktifkan lagi" and opens with "Aktif" preselected. */
    public static function restorable(string $state): bool
    {
        return in_array($state, self::RESTORABLE, true);
    }

    /**
     * Ask step: the dialog for these student accounts, with `$target` preselected when it applies to at least one of
     * them; without it "Aktif" is preselected when every picked student is restorable, or the only target that applies.
     *
     * @param  array<int|string, mixed>  $ids
     */
    #[On(self::OPEN_EVENT)]
    public function open(array $ids = [], ?string $target = null): void
    {
        Gate::authorize('changeStudentStatus', User::class);
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
            Gate::authorize('changeStudentStatus', $student);
        }

        $this->userIds = $userIds;
        $offered = array_keys(self::offered($students));

        $this->statusTarget = match (true) {
            $target !== null && in_array($target, $offered, true) => $target,
            $students->every(fn (User $user): bool => self::restorable(AccountControls::state($user))) => self::TARGET_ACTIVE,
            count($offered) === 1 => $offered[0],
            default => '',
        };

        $this->dispatch('sth-modal-open', id: self::DIALOG);
    }

    public function updatedStatusTarget(): void
    {
        $this->resetErrorBag(['statusTarget', 'statusArchiveDate', 'statusTemporaryPassword']);
    }

    /** The dialog closed (Batal, X, Esc, a click outside): forget the question. */
    public function forget(): void
    {
        $this->reset('userIds', 'statusTarget', 'statusReason', 'statusArchiveDate', 'statusTemporaryPassword');
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

        Gate::authorize('changeStudentStatus', User::class);

        $target = array_key_exists($this->statusTarget, self::TARGETS) ? $this->statusTarget : null;
        $students = $this->students();

        foreach ($students as $student) {
            Gate::authorize('changeStudentStatus', $student);
        }

        $plan = $target === null
            ? collect()
            : $students->filter(fn (User $user): bool => self::step($user, $target) !== null);

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
            $this->reset('statusTemporaryPassword');

            return;
        }

        $reason = trim($this->statusReason);
        $temporaryPassword = $this->statusTemporaryPassword;
        $this->reset('statusTemporaryPassword');
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

            Gate::authorize('changeStudentStatus', $user);

            try {
                if (! $this->apply($user, $target, $reason, $date, $temporaryPassword)) {
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

        if ($changed > 0) {
            // The Actions flush the public data cache inside the student's transaction; once more after the commits,
            // so a page rendered meanwhile does not keep the old names.
            PublicAchievements::flush();
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
     * The next Action on the way from the account's state to "$target" (one of TARGETS), or null when the account is
     * already there (or the target is unknown).
     */
    public static function step(User $user, string $target): ?string
    {
        $state = AccountControls::state($user);

        if (! array_key_exists($target, self::TARGETS) || $state === $target) {
            return null;
        }

        return match ($target) {
            self::TARGET_DISABLED => self::STEP_DISABLE,
            self::TARGET_ARCHIVED => self::STEP_ARCHIVE,
            default => match ($state) {
                // First back to an account that can log in.
                AccountControls::STATE_ARCHIVED => self::STEP_UNARCHIVE,
                AccountControls::STATE_DISABLED => self::STEP_ENABLE,
                AccountControls::STATE_FROZEN => self::STEP_UNFREEZE,
                // belum, aktif or kedaluwarsa, and not the target.
                default => match ($target) {
                    self::TARGET_FROZEN => self::STEP_FREEZE,
                    self::TARGET_ACTIVE => self::STEP_ACTIVATE,
                    self::TARGET_PENDING => $state === AccountControls::STATE_ACTIVE ? self::STEP_RESET : self::STEP_REOPEN,
                    // An active account, or a pending one without an initial or temporary password, gets one first.
                    default => $state === AccountControls::STATE_ACTIVE || ! $user->must_change_password ? self::STEP_RESET : self::STEP_EXPIRE,
                },
            },
        };
    }

    /**
     * Whether the way to "$target" resets the account (ResetStudentAccount), so "Simpan" needs the temporary password:
     * an account that is active once it can log in again (EnableAccount and UnarchiveStudent make an account active
     * when it had finished activation) on the way to "Belum aktivasi" or "Kedaluwarsa", and for "Kedaluwarsa" also a
     * pending one without an initial or temporary password.
     */
    public static function needsReset(User $user, string $target): bool
    {
        if (! in_array($target, [self::TARGET_PENDING, self::TARGET_EXPIRED], true) || self::step($user, $target) === null) {
            return false;
        }

        return self::activeOnceOpen($user) || ($target === self::TARGET_EXPIRED && ! $user->must_change_password);
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

    /**
     * The targets that change at least one of these accounts, in the order of TARGETS.
     *
     * @param  Collection<int, User>  $students
     * @return array<string, string>
     */
    private static function offered(Collection $students): array
    {
        return array_filter(
            self::TARGETS,
            fn (string $key): bool => $students->contains(fn (User $user): bool => self::step($user, $key) !== null),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Whether the account is `active` once it can log in again: an archived or disabled one becomes active when it had
     * finished activation (the rule of UnarchiveStudent and EnableAccount), the others keep their status.
     */
    private static function activeOnceOpen(User $user): bool
    {
        if (in_array($user->status, [UserStatus::Archived, UserStatus::Disabled], true)) {
            return ! $user->must_change_password && filled($user->email) && $user->email_verified_at !== null;
        }

        return $user->status === UserStatus::Active;
    }

    /** Close the dialog, forget the question and tell the host to redraw. */
    private function finish(): void
    {
        $this->forget();
        $this->dispatch('sth-modal-close', id: self::DIALOG);
        $this->dispatch(self::DONE_EVENT);
    }

    /**
     * Checks reason, target, the new archive date and the temporary password (each only when the plan needs it) in
     * one round, so every error shows at once. Returns the date for the steps that need it, else null.
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

        if ($target !== null
            && $plan->contains(fn (User $user): bool => self::needsReset($user, $target))
            && mb_strlen($this->statusTemporaryPassword) < ResetStudentAccount::MIN_PASSWORD) {
            $errors['statusTemporaryPassword'] = ResetStudentAccount::MSG_PASSWORD;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $date;
    }

    /**
     * Brings one student, read again just now, to "$target" in one transaction: step after step (each Action re-reads
     * and locks the account itself) until the account is in that state. False when it already is (skipped). A refused
     * step rolls the whole chain of this student back.
     *
     * @throws ValidationException
     */
    private function apply(User $user, string $target, string $reason, ?CarbonImmutable $date, string $temporaryPassword): bool
    {
        if (self::step($user, $target) === null) {
            return false;
        }

        $id = $user->getKey();

        return DB::transaction(function () use ($id, $target, $reason, $date, $temporaryPassword): bool {
            $done = [];

            for ($i = 0; $i < self::MAX_STEPS; $i++) {
                /** @var User|null $current */
                $current = User::query()->whereHas('student')->with('student')->lockForUpdate()->find($id);

                // Deleted meanwhile: skipped before the first step, else the chain cannot finish.
                if ($current === null) {
                    if ($done === []) {
                        return false;
                    }

                    break;
                }

                $step = self::step($current, $target);

                if ($step === null) {
                    return $done !== [];
                }

                // A step that left the account where it was would only repeat itself.
                if (in_array($step, $done, true)) {
                    break;
                }

                $this->run($step, $current, $reason, $date, $temporaryPassword);
                $done[] = $step;
            }

            throw ValidationException::withMessages(['user' => self::MSG_STUCK]);
        });
    }

    /** @throws ValidationException */
    private function run(string $step, User $user, string $reason, ?CarbonImmutable $date, string $temporaryPassword): void
    {
        $actor = $this->actor();

        match ($step) {
            self::STEP_UNARCHIVE => app(UnarchiveStudent::class)->handle($actor, $user, $date ?? '', $reason),
            self::STEP_ENABLE => app(EnableAccount::class)->handle($actor, $user, $reason, EnableAccount::archiveDatePassed($user->student) ? $date : null),
            self::STEP_UNFREEZE => app(UnfreezeAccount::class)->handle($actor, $user, $reason),
            self::STEP_ACTIVATE => app(ActivateStudentAccount::class)->handle($actor, $user, $reason),
            self::STEP_RESET => app(ResetStudentAccount::class)->handle($actor, $user, $reason, $temporaryPassword),
            self::STEP_REOPEN => app(ReopenActivationWindow::class)->handle($actor, $user, $reason),
            self::STEP_EXPIRE => app(ExpireActivationWindow::class)->handle($actor, $user, $reason),
            self::STEP_FREEZE => app(FreezeAccount::class)->handle($actor, $user, $reason),
            self::STEP_DISABLE => app(DisableAccount::class)->handle($actor, $user, $reason),
            self::STEP_ARCHIVE => app(ArchiveAccount::class)->handle($actor, $user, $reason),
        };
    }

    /** Whether the way of this account to "$target" needs the new archive date (its first step). */
    private static function needsDate(User $user, string $target): bool
    {
        return match (self::step($user, $target)) {
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
     * @return array{count: int, name: ?string, classroom: ?string, names: string, targets: array<string, string>, submit: string, danger: bool, chosen: ?array{changes: int, skipped: int, summary: string, effects: list<string>, needsDate: int, dateHint: string, needsPassword: int}}
     */
    private function dialog(Collection $students): array
    {
        $targets = self::offered($students);
        $target = $this->statusTarget;
        $chosen = null;

        if (array_key_exists($target, self::TARGETS)) {
            $plan = $students->filter(fn (User $user): bool => self::step($user, $target) !== null);
            $needsDate = $plan->filter(fn (User $user): bool => self::needsDate($user, $target))->count();

            $skipped = $students->count() - $plan->count();
            $chosen = [
                'changes' => $plan->count(),
                'skipped' => $skipped,
                'summary' => $plan->count().' akun akan diubah.'.($skipped > 0 ? ' '.$skipped.' dilewati karena '.self::SKIP_NOTES[$target].'.' : ''),
                'effects' => self::effects($target, $plan),
                'needsDate' => $needsDate,
                'dateHint' => $students->count() === 1
                    ? 'Harus setelah hari ini. Tanpa tanggal baru, akun ini diarsipkan lagi malam ini.'
                    : 'Untuk '.$needsDate.' siswa yang diarsipkan atau tanggal arsipnya sudah lewat. Harus setelah hari ini.',
                'needsPassword' => $plan->filter(fn (User $user): bool => self::needsReset($user, $target))->count(),
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
                self::TARGET_PENDING => 'Jadikan belum aktivasi',
                self::TARGET_ACTIVE => 'Aktifkan',
                self::TARGET_EXPIRED => 'Jadikan kedaluwarsa',
                self::TARGET_FROZEN => 'Bekukan',
                self::TARGET_DISABLED => 'Nonaktifkan',
                self::TARGET_ARCHIVED => 'Arsipkan',
                default => 'Simpan',
            },
            // Every target that stops the account from logging in.
            'danger' => in_array($target, [self::TARGET_EXPIRED, self::TARGET_FROZEN, self::TARGET_DISABLED, self::TARGET_ARCHIVED], true),
            'chosen' => $chosen,
        ];
    }

    /**
     * What the chosen target does, one sentence per kind of account.
     *
     * @param  Collection<int, User>  $plan
     * @return list<string>
     */
    private static function effects(string $target, Collection $plan): array
    {
        if ($target === self::TARGET_DISABLED) {
            return ['Akun nonaktif tidak bisa login dan semua sesinya diakhiri. Prestasinya tetap tersimpan.'];
        }

        if ($target === self::TARGET_ARCHIVED) {
            return ['Akun alumni tidak bisa login dan semua sesinya diakhiri. Prestasinya tetap tampil di peringkat.'];
        }

        $states = $plan->map(fn (User $user): string => AccountControls::state($user));
        $count = fn (string $state): int => $states->filter(fn (string $s): bool => $s === $state)->count();
        $resets = $plan->filter(fn (User $user): bool => self::needsReset($user, $target))->count();

        // Back to an account that can log in first.
        $lines = [
            ($n = $count(AccountControls::STATE_ARCHIVED)) > 0 ? $n.' akun diarsipkan dipulihkan dengan tanggal arsip otomatis baru.' : null,
            ($n = $count(AccountControls::STATE_DISABLED)) > 0 ? $n.' akun nonaktif diaktifkan lagi.' : null,
            ($n = $count(AccountControls::STATE_FROZEN)) > 0 ? $n.' akun dibekukan dibuka pembekuannya.' : null,
        ];

        $lines = [...$lines, ...match ($target) {
            self::TARGET_ACTIVE => [
                ($n = $plan->reject(fn (User $user): bool => self::activeOnceOpen($user))->count()) > 0
                    ? $n.' akun yang belum selesai aktivasi dijadikan aktif. Kata sandi awal atau sementaranya berlaku tanpa batas waktu sampai diganti.'
                    : null,
                $plan->contains(fn (User $user): bool => (bool) $user->must_change_password)
                    ? 'Saat login, siswa yang belum selesai aktivasi tetap menghubungkan email, memasukkan kode, dan membuat kata sandi baru.'
                    : null,
            ],
            self::TARGET_PENDING => [
                ($n = $count(AccountControls::STATE_EXPIRED)) > 0 ? $n.' akun kedaluwarsa mendapat 72 jam lagi untuk aktivasi.' : null,
                $resets > 0 ? $resets.' akun aktif di-reset: email dilepas, siswa masuk dengan NISN dan kata sandi sementara dalam 72 jam, lalu aktivasi lagi.' : null,
                $plan->contains(fn (User $user): bool => self::restoredPending($user, $target))
                    ? 'Akun yang belum selesai aktivasi tetap harus aktivasi. Kata sandi awal atau sementaranya berlaku lagi, paling sedikit 72 jam dari sekarang.'
                    : null,
            ],
            self::TARGET_EXPIRED => [
                $resets > 0 ? $resets.' akun di-reset dulu: email dilepas dan kata sandi sementara dipasang.' : null,
                'Kata sandi awal atau sementaranya tidak bisa dipakai login sampai statusnya diubah lagi atau aktivasinya dibuka ulang. Semua sesinya diakhiri.',
            ],
            default => [
                'Akun yang dibekukan tidak bisa login dan semua sesinya diakhiri sampai Admin membuka pembekuannya.',
            ],
        }];

        if (in_array($target, [self::TARGET_ACTIVE, self::TARGET_PENDING], true)
            && $plan->contains(fn (User $user): bool => AccountControls::frozenReason($user) === AccountControls::FROZEN_NOT_ME && ! self::needsReset($user, $target))) {
            $lines[] = 'Akun yang dibekukan lewat laporan "Bukan saya" sebaiknya di-reset kata sandi dan emailnya di Pengguna & peran.';
        }

        return array_values(array_filter($lines));
    }

    /** An archived, disabled or frozen account that comes back still activating (no reset on the way). */
    private static function restoredPending(User $user, string $target): bool
    {
        return in_array(AccountControls::state($user), [AccountControls::STATE_ARCHIVED, AccountControls::STATE_DISABLED, AccountControls::STATE_FROZEN], true)
            && (bool) $user->must_change_password
            && ! self::needsReset($user, $target);
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
