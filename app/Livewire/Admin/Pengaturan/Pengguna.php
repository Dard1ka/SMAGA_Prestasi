<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Roles\RequestRoleChange;
use App\Actions\Users\CancelPrincipalReplacement;
use App\Actions\Users\ChangeStaffRole;
use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\RequestPrincipalReplacement;
use App\Actions\Users\ResetStaffAccount;
use App\Actions\Users\StaffRoles;
use App\Enums\RoleName;
use App\Enums\UserStatus;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\PrincipalReplacement;
use App\Models\RoleChangeRequest;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pengguna & peran (Admin-Pengaturan tab + route admin.pengguna.index): staff accounts per role, "Tambah
 * pengguna" and "Ubah peran" (the role applies at once, also Admin / Kesiswaan / Tata Usaha / Humas, since 21 Okt 2026),
 * "Reset akun", "Nonaktifkan", "Ganti Kepala Sekolah" (24 h delay, cancellable)
 * and "Bantuan akun siswa" (name or NISN → "Reset kata sandi & email" / nonaktifkan / arsipkan with the W1-C
 * Actions; the consent toggles are gone since D-162, 20 Okt 2026).
 *
 * Targets are #[Locked] ids set only by the open*() methods; every action re-authorizes through the
 * UserPolicy / PrincipalReplacementPolicy inside the Action, and needs a password confirmation from the last
 * 15 minutes (SECURITY §3m-9). Passwords typed here are never shown back or logged.
 */
final class Pengguna extends Component
{
    use RequiresRecentPassword;

    /** D-158: "Bantuan akun siswa" searches a part of the name from NAME_MIN letters and lists MATCH_LIMIT matches. */
    public const NAME_MIN = 3;

    public const MATCH_LIMIT = 10;

    public const MSG_QUERY = 'Isi nama atau NISN siswa.';

    /** Topbar search (?q=): filters staff; digits prefill the student lookup. */
    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var array<string, string> */
    public array $new = [];

    #[Locked]
    public ?int $targetId = null;

    public string $role = '';

    public string $reason = '';

    public string $tempPassword = '';

    public string $tempPassword_confirmation = '';

    public string $principalTarget = '';

    public string $principalReason = '';

    #[Locked]
    public ?string $replacementId = null;

    public string $cancelReason = '';

    public string $studentQuery = '';

    /**
     * FS1 QA round 2 (QA-DC-05): the full NISN the top bar search ("Cari nama atau NISN") brought here. Its result stands
     * in "Bantuan akun siswa" at the bottom of the page, so a note above the staff table says so and links there.
     */
    #[Locked]
    public ?string $nisnFromSearch = null;

    #[Locked]
    public ?int $studentUserId = null;

    /**
     * D-158: the students whose name matched the search, when more than one did (view model, at most MATCH_LIMIT).
     *
     * @var list<array{id: int, name: string, classroom: ?string, nisn: ?string, status: string}>
     */
    #[Locked]
    public array $studentMatches = [];

    #[Locked]
    public bool $studentMatchesMore = false;

    public string $studentReason = '';

    public string $studentStatus = 'disabled';

    /**
     * "Reset kata sandi & email" (lead, 2026-10-21): the temporary password the Admin types in the dialog. Emptied when
     * the dialog opens, after the reset and on a refusal; never shown back, flashed or logged.
     */
    public string $studentPassword = '';

    /** The student account the open reset dialog asked about (openStudent()); resetStudent() does nothing without it. */
    #[Locked]
    public ?int $resetAskedFor = null;

    /** Page to come back to after a password confirmation (the tab or /admin/pengguna). */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
        $this->resetNewForm();
        $this->returnTo = request()->routeIs('admin.pengguna.index')
            ? route('admin.pengguna.index')
            : route('admin.pengaturan', ['tab' => 'pengguna']);

        if (preg_match('/^\d{10}$/', trim($this->search)) === 1) {
            $this->studentQuery = trim($this->search);
            $this->nisnFromSearch = $this->studentQuery;
            $this->search = '';
            $this->findStudent();
        }
    }

    // Staff ---------------------------------------------------------------------------------------

    public function createStaff(CreateStaffAccount $create): void
    {
        Gate::authorize('create', User::class);

        // Every problem at once (QA5 #65): the confirmation and the account's own fields in one answer.
        $errors = Validator::make(
            ['new' => $this->new],
            ['new.password' => ['required', 'string', 'same:new.password_confirmation']],
            ['new.password.same' => 'Konfirmasi kata sandi tidak cocok.'],
            ['new.password' => 'kata sandi sementara'],
        )->errors()->toArray();

        foreach (CreateStaffAccount::validator($this->new)->errors()->toArray() as $key => $messages) {
            $errors['new.'.$key] ??= $messages;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Like Livewire's validate(): a passing check drops the messages of the previous try.
        $this->resetErrorBag();

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $data = $this->new;

        try {
            $user = $create->handle($this->actor(), $data);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ['new.'.$key => $messages])->all(),
            );
        }

        // 21 Okt 2026 (the lead): the chosen role is given at once, privileged roles included.
        $this->resetNewForm();
        $this->dispatch('sth-modal-close', id: 'staf-baru');
        $this->dispatch('sth-toast', message: 'Akun staf dibuat. Berikan kata sandi sementara langsung ke pemiliknya.', tone: 'success');
    }

    public function openStaff(int $userId, string $modal): void
    {
        $target = $this->staffUser($userId);
        $ability = match ($modal) {
            'staf-peran' => 'updateRole',
            'staf-reset' => 'resetPassword',
            default => 'archive',
        };
        Gate::authorize($ability, $target);

        if ($modal === 'staf-reset' && $this->resetRefused(ResetStaffAccount::refusal($target), 'staf-reset')) {
            return;
        }

        $this->resetErrorBag();
        $this->targetId = (int) $target->getKey();
        $this->role = (string) ($target->getRoleNames()->first() ?? RoleName::Guru->value);
        $this->reset('reason', 'tempPassword', 'tempPassword_confirmation');
        $this->dispatch('sth-modal-open', id: in_array($modal, ['staf-peran', 'staf-reset', 'staf-nonaktif'], true) ? $modal : 'staf-nonaktif');
    }

    /** Applies at once for every role since 21 Okt 2026 (the lead): no second Admin, no 24 h delay. */
    public function changeRole(RequestRoleChange $change): void
    {
        $target = $this->staffUser((int) $this->targetId);
        Gate::authorize('updateRole', $target);

        $roleValues = array_map(fn (RoleName $r) => $r->value, StaffRoles::assignable());
        $this->validate(
            ['role' => ['required', 'in:'.implode(',', $roleValues)], 'reason' => ['required', 'string', 'max:500']],
            [],
            ['role' => 'peran', 'reason' => 'alasan'],
        );

        // QA9: the preselected role is the current one; "Simpan peran" without a change used to say "Peran diubah".
        if ($target->getRoleNames()->values()->all() === [$this->role]) {
            $this->addError('role', ChangeStaffRole::SAME_ROLE);

            return;
        }

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $change->handle($this->actor(), $target, RoleName::from($this->role), $this->reason);
        $this->closeModal('staf-peran', 'Peran diubah. Pengguna perlu login ulang.');
    }

    public function resetStaff(ResetStaffAccount $reset): void
    {
        $target = $this->staffUser((int) $this->targetId);
        Gate::authorize('resetPassword', $target);

        // Disabled or archived since the dialog opened (QA9).
        if ($this->resetRefused(ResetStaffAccount::refusal($target), 'staf-reset')) {
            return;
        }

        $this->validate(
            ['tempPassword' => ['required', 'string', 'confirmed'], 'reason' => ['required', 'string', 'max:500']],
            [],
            ['tempPassword' => 'kata sandi sementara', 'reason' => 'alasan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $reset->handle($this->actor(), $target, $this->tempPassword, $this->reason);
        } catch (ValidationException $e) {
            // The account changed between this check and the Action's lock: same toast as above.
            if ($this->resetRefused($e->errors()['user'][0] ?? null, 'staf-reset')) {
                return;
            }

            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $m, string $k) => [$k === 'password' ? 'tempPassword' : $k => $m])->all(),
            );
        }

        $this->closeModal('staf-reset', EnsureStaffTwoFactor::featureEnabled()
            ? 'Akun direset. Pengguna mengganti kata sandi dan memasang 2FA lagi saat login.'
            : 'Akun direset. Pengguna mengganti kata sandi saat login.');
    }

    public function disableStaff(DisableAccount $disable): void
    {
        $target = $this->staffUser((int) $this->targetId);
        Gate::authorize('archive', $target);

        if ($target->hasRole(RoleName::KepalaSekolah->value)) {
            $this->addError('reason', 'Ganti akun Kepala Sekolah dulu lewat "Ganti pejabat".');

            return;
        }

        $this->validate(['reason' => ['required', 'string', 'max:500']], [], ['reason' => 'alasan']);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $disable->handle($this->actor(), $target, $this->reason);
        $this->closeModal('staf-nonaktif', 'Akun dinonaktifkan. Semua sesinya sudah diakhiri.');
    }

    // Kepala Sekolah ------------------------------------------------------------------------------

    public function openPrincipal(): void
    {
        Gate::authorize('create', PrincipalReplacement::class);
        $this->resetErrorBag();
        $this->reset('principalTarget', 'principalReason');
        $this->dispatch('sth-modal-open', id: 'ganti-kepsek');
    }

    public function requestPrincipal(RequestPrincipalReplacement $request): void
    {
        Gate::authorize('create', PrincipalReplacement::class);

        $this->validate(
            ['principalTarget' => ['required', 'integer'], 'principalReason' => ['required', 'string', 'max:500']],
            [],
            ['principalTarget' => 'akun Kepala Sekolah baru', 'principalReason' => 'alasan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $target = $this->staffUser((int) $this->principalTarget);

        try {
            $replacement = $request->handle($this->actor(), $target, $this->principalReason);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $m, string $k) => [$k === 'reason' ? 'principalReason' : 'principalTarget' => $m])->all(),
            );
        }

        $this->closeModal('ganti-kepsek', $replacement->applied_at !== null
            ? 'Akun Kepala Sekolah sudah diganti.'
            : 'Penggantian dijadwalkan '.$replacement->effective_at->translatedFormat('j M Y H:i').'. Masih bisa dibatalkan sampai waktu itu.');
    }

    public function openCancel(string $replacementId): void
    {
        $replacement = PrincipalReplacement::query()->findOrFail($replacementId);

        if ($this->replacementDecidedElsewhere($replacement)) {
            return;
        }

        Gate::authorize('cancel', $replacement);

        $this->resetErrorBag();
        $this->replacementId = (string) $replacement->getKey();
        $this->reset('cancelReason');
        $this->dispatch('sth-modal-open', id: 'batal-kepsek');
    }

    public function cancelReplacement(CancelPrincipalReplacement $cancel): void
    {
        $replacement = PrincipalReplacement::query()->findOrFail((string) $this->replacementId);

        if ($this->replacementDecidedElsewhere($replacement)) {
            return;
        }

        Gate::authorize('cancel', $replacement);

        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], [], ['cancelReason' => 'alasan']);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $cancel->handle($this->actor(), $replacement, $this->cancelReason);
        $this->replacementId = null;
        $this->closeModal('batal-kepsek', 'Penggantian Kepala Sekolah dibatalkan.');
    }

    // Students ------------------------------------------------------------------------------------

    /**
     * "Bantuan akun siswa": a full NISN (exact match, SECURITY §3m-3) or, since D-158, a part of the name (at least
     * NAME_MIN letters). One match opens it; several are listed (at most MATCH_LIMIT) to pick from with chooseStudent().
     */
    public function findStudent(): void
    {
        Gate::authorize('viewAny', User::class);

        $this->studentUserId = null;
        $this->studentMatches = [];
        $this->studentMatchesMore = false;
        $query = preg_replace('/\s+/u', ' ', trim($this->studentQuery)) ?? '';
        $this->studentQuery = $query;
        $byNisn = preg_match('/^\d+$/', $query) === 1;
        $this->validate(
            ['studentQuery' => ['required', 'string', 'max:100', $byNisn ? 'regex:/^\s*\d{10}\s*$/' : 'min:'.self::NAME_MIN]],
            [
                'studentQuery.required' => self::MSG_QUERY,
                'studentQuery.regex' => 'Isi NISN (10 digit) lengkap.',
                'studentQuery.min' => 'Ketik minimal '.self::NAME_MIN.' huruf nama siswa.',
            ],
            ['studentQuery' => 'nama atau NISN'],
        );

        if ($byNisn) {
            $student = Student::query()->where('nisn', $query)->first(['id', 'user_id']);

            if ($student === null) {
                $this->addError('studentQuery', 'Siswa tidak ditemukan.');

                return;
            }

            $this->openStudentAccount((int) $student->user_id);

            return;
        }

        // Wildcards are dropped, not escaped: the escape character differs between MySQL and SQLite, and no name has one.
        $like = '%'.str_replace(['%', '_', '\\'], '', mb_strtolower($query)).'%';
        $users = User::query()
            ->whereHas('student')
            ->whereRaw('LOWER(name) LIKE ?', [$like])
            ->with('student.classroom:id,name')
            ->orderBy('name')
            ->limit(self::MATCH_LIMIT + 1)
            ->get(['id', 'name', 'status']);

        if ($users->isEmpty()) {
            $this->addError('studentQuery', 'Siswa tidak ditemukan.');

            return;
        }

        if ($users->count() === 1) {
            $this->openStudentAccount((int) $users->first()->getKey());

            return;
        }

        $this->studentMatchesMore = $users->count() > self::MATCH_LIMIT;
        $this->studentMatches = $users->take(self::MATCH_LIMIT)->map(fn (User $u): array => [
            'id' => (int) $u->getKey(),
            'name' => (string) $u->name,
            'classroom' => $u->student?->classroom?->name,
            'nisn' => $u->student?->nisn,
            'status' => $u->status->value,
        ])->values()->all();
    }

    /** D-158: one of the listed name matches. The id comes from the browser, so it must be a student account. */
    public function chooseStudent(int $userId): void
    {
        Gate::authorize('viewAny', User::class);

        abort_unless(User::query()->whereKey($userId)->whereHas('student')->exists(), 404);

        $this->studentMatches = [];
        $this->openStudentAccount($userId);
    }

    private function openStudentAccount(int $userId): void
    {
        $student = Student::query()->where('user_id', $userId)->firstOrFail(['id', 'user_id']);

        $this->studentUserId = (int) $student->user_id;
        $this->resetAskedFor = null;
        $this->reset('studentReason', 'studentPassword');
    }

    /** Ask step of "Reset kata sandi & email" and "Nonaktifkan atau arsipkan" (CLAUDE.md §7, D-128 pattern). */
    public function openStudent(string $modal): void
    {
        $target = $this->studentUser();
        Gate::authorize($modal === 'siswa-reset' ? 'resetPassword' : 'archive', $target);

        if ($modal === 'siswa-reset' && $this->resetRefused(ResetStudentAccount::refusal($target), 'siswa-reset')) {
            return;
        }

        $this->resetErrorBag();
        $this->reset('studentReason', 'studentPassword');
        $this->resetAskedFor = $modal === 'siswa-reset' ? (int) $target->getKey() : null;
        $this->studentStatus = UserStatus::Disabled->value;
        $this->dispatch('sth-modal-open', id: $modal === 'siswa-reset' ? 'siswa-reset' : 'siswa-nonaktif');
    }

    /**
     * "Reset kata sandi & email" (lead, 2026-10-21): the password the Admin typed, the email removed, the student
     * activates again (email → code → new password) within 72 hours. A silent no-op without the dialog's question.
     */
    public function resetStudent(ResetStudentAccount $reset): void
    {
        if ($this->resetAskedFor === null || $this->resetAskedFor !== $this->studentUserId) {
            return;
        }

        $target = $this->studentUser();
        Gate::authorize('resetPassword', $target);

        // Disabled or archived since the dialog opened (QA9).
        if ($this->resetRefused(ResetStudentAccount::refusal($target), 'siswa-reset')) {
            return;
        }

        // Both fields at once, with the Action's own rules and sentences.
        $errors = [];

        if (trim($this->studentReason) === '' || mb_strlen(trim($this->studentReason)) > 500) {
            $errors['studentReason'] = ResetStudentAccount::MSG_REASON;
        }

        if (mb_strlen($this->studentPassword) < ResetStudentAccount::MIN_PASSWORD) {
            $errors['studentPassword'] = ResetStudentAccount::MSG_PASSWORD;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->resetErrorBag();

        if (! $this->passwordRecentlyConfirmed()) {
            $this->reset('studentPassword');

            return;
        }

        $password = $this->studentPassword;
        $this->reset('studentPassword');

        try {
            $reset->handle($this->actor(), $target, $this->studentReason, $password);
        } catch (ValidationException $e) {
            // The account changed between this check and the Action's lock (QA9); other messages go under their field.
            if ($this->resetRefused($e->errors()['user'][0] ?? null, 'siswa-reset')) {
                return;
            }

            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $m, string $k) => [$k === 'password' ? 'studentPassword' : 'studentReason' => $m])->all(),
            );
        }

        $this->resetAskedFor = null;
        $this->closeModal('siswa-reset', 'Akun direset dan email dilepas. Siswa masuk dengan NISN dan kata sandi sementara dalam 72 jam, lalu menghubungkan email dan membuat kata sandi baru.');
    }

    /** QA9: a decision in the "Perubahan peran" panel changes rows of the staff table (role, "Menunggu: …"). */
    #[On('role-change-decided')]
    public function refreshStaff(): void
    {
        // Re-render only.
    }

    public function disableStudent(DisableAccount $disable, ArchiveAccount $archive): void
    {
        $target = $this->studentUser();
        Gate::authorize('archive', $target);

        $this->validate(
            ['studentReason' => ['required', 'string', 'max:500'], 'studentStatus' => ['required', 'in:disabled,archived']],
            [],
            ['studentReason' => 'alasan', 'studentStatus' => 'status'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $this->studentStatus === UserStatus::Archived->value
            ? $archive->handle($this->actor(), $target, $this->studentReason)
            : $disable->handle($this->actor(), $target, $this->studentReason);

        $this->closeModal('siswa-nonaktif', $this->studentStatus === UserStatus::Archived->value
            ? 'Akun diarsipkan. Prestasinya tetap tampil.'
            : 'Akun dinonaktifkan. Semua sesinya sudah diakhiri.');
    }

    // View ----------------------------------------------------------------------------------------

    public function render(): View
    {
        // The search narrows the table only (QA5): the Kepala Sekolah, the candidates to replace them and the
        // account of an open dialog always come from every staff account.
        $all = $this->staff();
        $term = mb_strtolower(trim($this->search));
        $staff = $term === '' ? $all : $all->filter(fn (User $u): bool => str_contains(mb_strtolower((string) $u->name), $term)
            || str_contains(mb_strtolower((string) $u->username), $term))->values();
        $target = $this->targetId !== null ? $all->firstWhere('id', $this->targetId) : null;
        $studentAccount = $this->studentUserId !== null ? $this->studentSummary() : null;

        return view('livewire.admin.pengaturan.pengguna', [
            'staff' => $staff,
            // QA9: user id => why "Reset akun" is not offered (the account cannot log in).
            'resetRefusals' => $staff->mapWithKeys(fn (User $u): array => [(int) $u->getKey() => ResetStaffAccount::refusal($u)])->filter()->all(),
            'target' => $target,
            'principal' => $all->first(fn (User $u) => $u->hasRole(RoleName::KepalaSekolah->value) && $u->canLogIn()),
            'pending' => PrincipalReplacement::query()->pending()->with(['fromUser:id,name', 'toUser:id,name'])->latest('created_at')->first(),
            'principalCandidates' => $all
                ->filter(fn (User $u) => $u->canLogIn() && ! $u->hasAnyRole([RoleName::Admin->value, RoleName::KepalaSekolah->value]))
                ->mapWithKeys(fn (User $u) => [(string) $u->getKey() => $u->name.' ('.$u->username.')'])
                ->all(),
            // STH_STAFF_2FA=false: no 2FA words on this screen (no chip, no "memasang 2FA" in the dialogs).
            'twoFactor' => EnsureStaffTwoFactor::featureEnabled(),
            'roleOptions' => collect(StaffRoles::assignable())->mapWithKeys(fn (RoleName $r) => [$r->value => $r->label()])->all(),
            // User id => label of a role request made before 21 Okt 2026 that still waits (new changes apply at once).
            'pendingRoles' => RoleChangeRequest::query()->pending()->get(['id', 'user_id', 'to_role'])
                ->mapWithKeys(fn (RoleChangeRequest $r) => [(int) $r->user_id => $r->to_role->label()])->all(),
            'studentAccount' => $studentAccount,
            // The note of a top bar NISN search, while "Bantuan akun siswa" still shows that NISN's answer.
            'nisnNotice' => $this->nisnFromSearch !== null && trim($this->studentQuery) === $this->nisnFromSearch
                ? ['nisn' => $this->nisnFromSearch, 'name' => $studentAccount['name'] ?? null]
                : null,
            'canCreate' => Gate::allows('create', User::class),
            'canReplace' => Gate::allows('create', PrincipalReplacement::class),
        ]);
    }

    /** @return Collection<int, User> every non-student account, Kepala Sekolah first, then by role and name */
    private function staff(): Collection
    {
        $order = [RoleName::KepalaSekolah->value, RoleName::Admin->value, RoleName::Kesiswaan->value, RoleName::TataUsaha->value, RoleName::Humas->value, RoleName::Guru->value];

        return User::query()
            ->whereDoesntHave('student')
            ->with(['roles'])
            ->get(['id', 'name', 'username', 'email', 'status', 'two_factor_confirmed_at', 'last_login_at', 'deleted_at'])
            ->sortBy(fn (User $u) => sprintf('%02d-%s', ($i = array_search($u->getRoleNames()->first(), $order, true)) === false ? 99 : $i, mb_strtolower($u->name)))
            ->values();
    }

    /** @return array{name: string, classroom: ?string, status: UserStatus, email: ?string, activated: bool, lastLogin: ?string, resetRefusal: ?string} */
    private function studentSummary(): ?array
    {
        $user = User::query()->with('student.classroom:id,name')->find($this->studentUserId, ['id', 'name', 'email', 'status', 'must_change_password', 'last_login_at']);

        if ($user === null || $user->student === null) {
            return null;
        }

        return [
            'name' => (string) $user->name,
            'classroom' => $user->student->classroom?->name,
            'status' => $user->status,
            'email' => $user->email !== null ? self::maskEmail($user->email) : null,
            'activated' => ! $user->must_change_password && $user->status === UserStatus::Active,
            'lastLogin' => $user->last_login_at?->translatedFormat('j M Y H:i'),
            // QA9: why the reset is not offered (the account cannot log in), else null.
            'resetRefusal' => ResetStudentAccount::refusal($user),
        ];
    }

    private function staffUser(int $userId): User
    {
        /** @var User $user */
        $user = User::query()->whereDoesntHave('student')->findOrFail($userId);

        return $user;
    }

    private function studentUser(): User
    {
        abort_if($this->studentUserId === null, 404);

        /** @var User $user */
        $user = User::query()->whereHas('student')->findOrFail($this->studentUserId);

        return $user;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    /**
     * QA9: a reset promises a login with the temporary password, which a disabled or archived account never gets. The
     * table and the student card offer no reset for such an account; a stale screen gets this toast instead of a
     * dialog (or a reset that only looked done).
     */
    private function resetRefused(?string $refusal, string $dialog): bool
    {
        if ($refusal === null) {
            return false;
        }

        $this->reset('reason', 'tempPassword', 'tempPassword_confirmation', 'studentReason', 'studentPassword');
        $this->resetAskedFor = null;
        $this->resetErrorBag();
        $this->dispatch('sth-modal-close', id: $dialog);
        $this->dispatch('sth-toast', message: $refusal, tone: 'warning');

        return true;
    }

    /**
     * The replacement took effect at its time (ApplyPrincipalReplacement) or another Admin cancelled it meanwhile
     * (QA9): the policy would refuse with a full-screen 403. Say so, close the dialog and let the page redraw.
     */
    private function replacementDecidedElsewhere(PrincipalReplacement $replacement): bool
    {
        if ($replacement->isPending()) {
            return false;
        }

        $this->replacementId = null;
        $this->reset('cancelReason');
        $this->resetErrorBag();
        $this->dispatch('sth-modal-close', id: 'batal-kepsek');
        $this->dispatch('sth-toast', message: $replacement->applied_at !== null
            ? 'Penggantian Kepala Sekolah ini sudah berlaku. Halaman sudah diperbarui.'
            : 'Penggantian Kepala Sekolah ini sudah dibatalkan. Halaman sudah diperbarui.', tone: 'warning');

        return true;
    }

    private function closeModal(string $id, string $message): void
    {
        $this->reset('reason', 'tempPassword', 'tempPassword_confirmation', 'principalTarget', 'principalReason', 'cancelReason', 'studentReason', 'studentPassword');
        $this->dispatch('sth-modal-close', id: $id);
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    private function resetNewForm(): void
    {
        $this->new = [
            'name' => '', 'username' => '', 'email' => '', 'role' => RoleName::Guru->value,
            'honorific' => '', 'nip' => '', 'rank' => '', 'position' => '',
            'password' => '', 'password_confirmation' => '',
        ];
    }

    /** "nadia.anindya@gmail.com" → "n•••@gmail.com" */
    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 1).'•••@'.$domain;
    }

    protected function returnUrl(): string
    {
        return $this->returnTo !== '' ? $this->returnTo : route('admin.pengaturan', ['tab' => 'pengguna']);
    }
}
