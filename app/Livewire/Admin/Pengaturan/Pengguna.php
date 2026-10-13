<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Accounts\ArchiveAccount;
use App\Actions\Accounts\DisableAccount;
use App\Actions\Accounts\ResetStudentAccount;
use App\Actions\Roles\RequestRoleChange;
use App\Actions\Students\UpdateConsent;
use App\Actions\Users\CancelPrincipalReplacement;
use App\Actions\Users\ChangeStaffRole;
use App\Actions\Users\CreateStaffAccount;
use App\Actions\Users\RequestPrincipalReplacement;
use App\Actions\Users\ResetStaffAccount;
use App\Actions\Users\StaffRoles;
use App\Enums\RoleName;
use App\Enums\UserStatus;
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
 * pengguna", "Ubah peran", "Reset akun", "Nonaktifkan", "Ganti Kepala Sekolah" (24 h delay, cancellable)
 * and "Bantuan akun siswa" (exact NISN → reset / nonaktifkan / arsipkan with the W1-C Actions, and the
 * consent toggles photo/publish through App\Actions\Students\UpdateConsent).
 *
 * Targets are #[Locked] ids set only by the open*() methods; every action re-authorizes through the
 * UserPolicy / PrincipalReplacementPolicy inside the Action, and needs a password confirmation from the last
 * 15 minutes (SECURITY §3m-9). Passwords typed here are never shown back or logged.
 */
final class Pengguna extends Component
{
    use RequiresRecentPassword;

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

    #[Locked]
    public ?int $studentUserId = null;

    public string $studentReason = '';

    public string $studentStatus = 'disabled';

    /** B1 — K10: temporary password of the last student reset, shown once; cleared by forgetIssuedPassword(). */
    #[Locked]
    public ?string $issuedPassword = null;

    /** Consent toggles of the found student (D-16), saved through App\Actions\Students\UpdateConsent. */
    public bool $photoConsent = false;

    public bool $publishConsent = false;

    public string $consentNote = '';

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

        // W4: a privileged role (Admin, Kesiswaan, Tata Usaha, Humas) waits as a RoleChangeRequest like "Ubah peran".
        $request = RoleChangeRequest::query()->pending()->where('user_id', $user->getKey())->first();

        $this->resetNewForm();
        $this->dispatch('sth-modal-close', id: 'staf-baru');

        if ($request !== null) {
            $this->dispatch('role-change-requested');
        }

        $this->dispatch('sth-toast', message: match (true) {
            $request === null => 'Akun staf dibuat. Berikan kata sandi sementara langsung ke pemiliknya.',
            $request->usesDelay() => 'Akun staf dibuat tanpa peran. Peran '.$request->to_role->label().' berlaku '.$request->effective_at?->translatedFormat('j M Y H:i').' bila tidak dibatalkan.',
            default => 'Akun staf dibuat tanpa peran. Peran '.$request->to_role->label().' menunggu persetujuan Admin lain.',
        }, tone: 'success');
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

    /** B5 (backlog #13): privileged roles become a RoleChangeRequest (second Admin or 24 h delay). */
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

        $request = $change->handle($this->actor(), $target, RoleName::from($this->role), $this->reason);
        $this->dispatch('role-change-requested');
        $this->closeModal('staf-peran', match (true) {
            $request === null => 'Peran diubah. Pengguna perlu login ulang.',
            $request->usesDelay() => 'Perubahan peran berlaku '.$request->effective_at?->translatedFormat('j M Y H:i').' bila tidak dibatalkan.',
            default => 'Perubahan peran menunggu persetujuan Admin lain.',
        });
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

        $this->closeModal('staf-reset', 'Akun direset. Pengguna mengganti kata sandi dan memasang 2FA lagi saat login.');
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

    public function findStudent(): void
    {
        Gate::authorize('viewAny', User::class);

        $this->studentUserId = null;
        $query = trim($this->studentQuery);
        $this->validate(
            ['studentQuery' => ['required', 'regex:/^\d{10}$/']],
            ['studentQuery.regex' => 'Isi NISN (10 digit) lengkap.'],
            ['studentQuery' => 'NISN'],
        );

        // Exact match only (no prefix search, SECURITY §3m-3).
        $student = Student::query()->where('nisn', $query)->first(['id', 'user_id', 'photo_consent', 'publish_consent']);

        if ($student === null) {
            $this->addError('studentQuery', 'Siswa tidak ditemukan.');

            return;
        }

        $this->studentUserId = (int) $student->user_id;
        $this->photoConsent = (bool) $student->photo_consent;
        $this->publishConsent = (bool) $student->publish_consent;
        $this->reset('studentReason', 'consentNote');
    }

    /** Persetujuan foto & publikasi (photo_consent / publish_consent), audited with its basis. */
    public function saveConsent(UpdateConsent $update): void
    {
        $student = $this->studentUser()->student;
        abort_if($student === null, 404);
        Gate::authorize('updateConsent', $student);

        $this->validate(
            ['photoConsent' => ['boolean'], 'publishConsent' => ['boolean'], 'consentNote' => ['required', 'string', 'max:'.UpdateConsent::MAX_NOTE]],
            [],
            ['consentNote' => 'dasar perubahan'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        try {
            $update->handle($this->actor(), $student, $this->photoConsent, $this->publishConsent, $this->consentNote);
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['consentNote' => $e->errors()['note'] ?? collect($e->errors())->flatten()->all()]);
        }

        $this->reset('consentNote');
        $this->dispatch('sth-toast', message: 'Persetujuan disimpan. Halaman publik ikut diperbarui.', tone: 'success');
    }

    public function openStudent(string $modal): void
    {
        $target = $this->studentUser();
        Gate::authorize($modal === 'siswa-reset' ? 'resetPassword' : 'archive', $target);

        if ($modal === 'siswa-reset' && $this->resetRefused(ResetStudentAccount::refusal($target), 'siswa-reset')) {
            return;
        }

        $this->resetErrorBag();
        $this->reset('studentReason');
        $this->studentStatus = UserStatus::Disabled->value;
        $this->dispatch('sth-modal-open', id: $modal === 'siswa-reset' ? 'siswa-reset' : 'siswa-nonaktif');
    }

    public function resetStudent(ResetStudentAccount $reset): void
    {
        $target = $this->studentUser();
        Gate::authorize('resetPassword', $target);

        // Disabled or archived since the dialog opened (QA9).
        if ($this->resetRefused(ResetStudentAccount::refusal($target), 'siswa-reset')) {
            return;
        }

        $this->validate(['studentReason' => ['required', 'string', 'max:500']], [], ['studentReason' => 'alasan']);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        // B1 — K10: random temporary password (72 h), shown once in the "siswa-sandi" dialog, never stored in plaintext.
        try {
            $this->issuedPassword = $reset->handle($this->actor(), $target, $this->studentReason);
        } catch (ValidationException $e) {
            // The account changed between this check and the Action's lock (QA9); other messages go under the field.
            if ($this->resetRefused($e->errors()['user'][0] ?? null, 'siswa-reset')) {
                return;
            }

            throw ValidationException::withMessages(['studentReason' => collect($e->errors())->flatten()->all()]);
        }

        $this->reset('studentReason');
        $this->dispatch('sth-modal-close', id: 'siswa-reset');
        $this->dispatch('sth-modal-open', id: 'siswa-sandi');
    }

    /**
     * B1 — K10: the Admin confirms the temporary password was handed over; it disappears from the page. QA9: also the
     * dialog's on-close, so closing it with X, Esc or a click outside forgets it too. "Sudah saya berikan" closes the
     * dialog, which calls this again: only the first call says "Akun direset".
     */
    public function forgetIssuedPassword(): void
    {
        $issued = $this->issuedPassword !== null;
        $this->issuedPassword = null;

        if (! $issued) {
            return;
        }

        $this->dispatch('sth-modal-close', id: 'siswa-sandi');
        $this->dispatch('sth-toast', message: 'Akun direset. Siswa masuk dengan kata sandi sementara lalu aktivasi lagi.', tone: 'success');
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
            'roleOptions' => collect(StaffRoles::assignable())->mapWithKeys(fn (RoleName $r) => [$r->value => $r->label()])->all(),
            // W4: user id => label of the privileged role still waiting (new accounts have no role until then).
            'pendingRoles' => RoleChangeRequest::query()->pending()->get(['id', 'user_id', 'to_role'])
                ->mapWithKeys(fn (RoleChangeRequest $r) => [(int) $r->user_id => $r->to_role->label()])->all(),
            'studentAccount' => $this->studentUserId !== null ? $this->studentSummary() : null,
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

    /** @return array{name: string, classroom: ?string, status: UserStatus, email: ?string, activated: bool, lastLogin: ?string, canConsent: bool, resetRefusal: ?string} */
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
            'canConsent' => Gate::allows('updateConsent', $user->student),
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

        $this->reset('reason', 'tempPassword', 'tempPassword_confirmation', 'studentReason');
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
        $this->reset('reason', 'tempPassword', 'tempPassword_confirmation', 'principalTarget', 'principalReason', 'cancelReason', 'studentReason');
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
