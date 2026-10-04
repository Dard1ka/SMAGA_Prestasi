<?php

declare(strict_types=1);

namespace App\Livewire\Akun;

use App\Actions\Accounts\AccountControls;
use App\Actions\Accounts\ActivationReport;
use App\Actions\Accounts\ReopenActivationWindow;
use App\Actions\Accounts\UnfreezeAccount;
use App\Actions\Accounts\UpdateActivationWindow;
use App\Enums\Permission;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Status aktivasi (`kesiswaan.aktivasi.index`, `admin.aktivasi.index`; docs/UI-MAP.md §4a/§5, SECURITY K9/K2,
 * B1): per-class activation report (counts, activation time, last login, masked email/NIS, automatic flags),
 * filters (kelas, status, nama/NIS), formula-safe CSV export (password confirmed in the last 15 min, audited),
 * K2 "Buka ulang aktivasi" (72 h, reason, Kesiswaan + Admin), Admin "Buka pembekuan" and the K1 window length
 * (Admin), K7 alerts. Every action re-authorizes; the target id is #[Locked] and set only by open*().
 *
 * M1-E (lead, 2026-10-04, item 16): "Lihat" on a class row opens the dialog `aktivasi-kelas` with that class's
 * students (status tabs, steps of 40, the same row actions) instead of filtering the list at the bottom of
 * the page. `?kelas=<classroom id>` opens the page with that dialog. The list at the bottom stays for
 * cross-class lookups and the CSV; its class filter is `?kelas_daftar=`.
 */
#[Title('Status aktivasi')]
#[Layout('layouts::app', ['subtitle' => 'Aktivasi akun siswa per kelas.'])]
final class StatusAktivasi extends Component
{
    use RequiresRecentPassword;

    /** Rows per step: one full class fits; 100 rows made the first page about 300 kB. */
    public const PAGE = 40;

    public const MAX = 1000;

    public const ACTION_REOPEN = 'buka';

    public const ACTION_UNFREEZE = 'cair';

    public const CLASS_DIALOG = 'aktivasi-kelas';

    /** Class shown in the dialog ("Lihat"); '' = no dialog. Checked against the classes that have students. */
    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    /** Status tab inside the dialog. */
    public string $kelasStatus = '';

    #[Locked]
    public int $kelasLimit = self::PAGE;

    /** Class filter of the cross-class list at the bottom (and of its CSV). */
    #[Url(as: 'kelas_daftar', except: '')]
    public string $daftarKelas = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Locked]
    public int $limit = self::PAGE;

    /** True only in the request that mounts the page with `?kelas=`: the dialog is rendered open. */
    private bool $openClassOnLoad = false;

    #[Locked]
    public ?int $targetId = null;

    #[Locked]
    public string $action = '';

    public string $reason = '';

    public string $windowDays = '';

    #[Locked]
    public string $returnTo = '';

    public function mount(AccountControls $controls): void
    {
        Gate::authorize(Permission::StudentView->value);

        $route = request()->routeIs('admin.*') ? 'admin.aktivasi.index' : 'kesiswaan.aktivasi.index';
        $this->returnTo = Route::has($route) ? route($route) : url()->current();
        $this->windowDays = (string) $controls->windowDays();

        if (! array_key_exists($this->status, AccountControls::STATES)) {
            $this->status = '';
        }

        // `?kelas=` opens the class dialog; an unknown or empty class is dropped.
        $this->openClassOnLoad = self::knownClass($this->kelas);

        if (! $this->openClassOnLoad) {
            $this->kelas = '';
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['daftarKelas', 'status', 'q'], true)) {
            $this->limit = self::PAGE;
            $this->forgetClass();
        }

        // The address changed under the page (browser history): follow it.
        if ($property === 'kelas') {
            $this->kelasStatus = '';
            $this->kelasLimit = self::PAGE;

            if (self::knownClass($this->kelas)) {
                $this->dispatch('sth-modal-open', id: self::CLASS_DIALOG);
            } else {
                $this->kelas = '';
                $this->dispatch('sth-modal-close', id: self::CLASS_DIALOG);
            }
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, AccountControls::STATES) ? $status : '';
        $this->limit = self::PAGE;
        $this->forgetClass();
    }

    public function resetFilters(): void
    {
        $this->reset('daftarKelas', 'status', 'q');
        $this->limit = self::PAGE;
        $this->forgetClass();
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE;
        $this->forgetClass();
    }

    // Class dialog ("Lihat") ----------------------------------------------------------------------

    public function showClass(string $classroomId): void
    {
        Gate::authorize(Permission::StudentView->value);
        abort_unless(self::knownClass($classroomId), 404);

        $this->kelas = $classroomId;
        $this->kelasStatus = '';
        $this->kelasLimit = self::PAGE;
        $this->dispatch('sth-modal-open', id: self::CLASS_DIALOG);
    }

    public function setClassStatus(string $status): void
    {
        $this->kelasStatus = array_key_exists($status, AccountControls::STATES) ? $status : '';
        $this->kelasLimit = self::PAGE;
    }

    public function showMoreClass(): void
    {
        $this->kelasLimit += self::PAGE;
    }

    public function closeClass(): void
    {
        $this->forgetClass();
        $this->dispatch('sth-modal-close', id: self::CLASS_DIALOG);
    }

    // Actions -------------------------------------------------------------------------------------

    public function openReopen(int $userId): void
    {
        Gate::authorize(Permission::StudentImport->value);
        $this->openAction($this->studentAccount($userId), self::ACTION_REOPEN);
    }

    public function openUnfreeze(int $userId): void
    {
        Gate::authorize(Permission::UserResetPassword->value);
        $this->openAction($this->account($userId), self::ACTION_UNFREEZE);
    }

    public function submitAction(ReopenActivationWindow $reopen, UnfreezeAccount $unfreeze): void
    {
        abort_if($this->targetId === null, 404);

        $this->validate(['reason' => ['required', 'string', 'max:500']], ['required' => 'Isi alasannya.', 'max' => 'Maksimal :max karakter.'], ['reason' => 'alasan']);

        try {
            if ($this->action === self::ACTION_REOPEN) {
                Gate::authorize(Permission::StudentImport->value);
                $until = $reopen->handle($this->actor(), $this->studentAccount($this->targetId), $this->reason);
                $message = 'Aktivasi dibuka lagi sampai '.$until->translatedFormat('j M Y H:i').'.';
            } elseif ($this->action === self::ACTION_UNFREEZE) {
                Gate::authorize(Permission::UserResetPassword->value);

                if (! $this->passwordRecentlyConfirmed()) {
                    return;
                }

                $unfreeze->handle($this->actor(), $this->account($this->targetId), $this->reason);
                $message = 'Pembekuan dibuka. Akun bisa dipakai masuk lagi.';
            } else {
                abort(404);
            }
        } catch (ValidationException $e) {
            throw ValidationException::withMessages(['reason' => collect($e->errors())->flatten()->all()]);
        }

        $this->reset('reason', 'targetId', 'action');
        $this->dispatch('sth-modal-close', id: 'aktivasi-aksi');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    public function saveWindow(UpdateActivationWindow $update): void
    {
        Gate::authorize(Permission::SettingManage->value);
        $this->forgetClass();

        $this->validate(
            ['windowDays' => ['required', 'integer', 'min:1', 'max:'.AccountControls::MAX_WINDOW_DAYS]],
            ['required' => 'Wajib diisi.', 'integer' => 'Isi angka hari.', 'min' => 'Minimal :min hari.', 'max' => 'Maksimal :max hari.'],
            ['windowDays' => 'jendela aktivasi'],
        );

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $update->handle($this->actor(), (int) $this->windowDays);
        $this->dispatch('sth-toast', message: 'Jendela aktivasi disimpan: '.(int) $this->windowDays.' hari untuk impor berikutnya.', tone: 'success');
    }

    public function export(ActivationReport $report): ?StreamedResponse
    {
        Gate::authorize(Permission::StudentView->value);
        // The CSV belongs to the list at the bottom: coming back from the password page must not reopen a dialog.
        $this->forgetClass();

        if (! $this->passwordRecentlyConfirmed()) {
            return null;
        }

        $rows = $report->rows($this->daftarKelas ?: null, $this->status ?: null, $this->q);

        activity('account')
            ->causedBy($this->actor())
            ->event('account.activation_report_exported')
            ->withProperties(['filters' => array_filter(['kelas' => $this->daftarKelas, 'status' => $this->status, 'q' => $this->q !== '' ? 'ya' : '']), 'rows' => $rows->count()])
            ->log('account.activation_report_exported');

        return response()->streamDownload(function () use ($report, $rows): void {
            $out = fopen('php://output', 'wb');
            $report->writeCsv($rows, $out);
            fclose($out);
        }, 'status-aktivasi-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // View ----------------------------------------------------------------------------------------

    public function render(ActivationReport $report): View
    {
        $this->limit = max(self::PAGE, min(self::MAX, $this->limit));
        $all = $report->rows();
        $filtered = $report->rows($this->daftarKelas ?: null, $this->status ?: null, $this->q);
        $isAdmin = Gate::allows(Permission::UserResetPassword->value);
        $canReopen = Gate::allows(Permission::StudentImport->value);
        $target = $this->targetId !== null ? User::query()->with('student.classroom:id,name')->find($this->targetId, ['id', 'name']) : null;

        return view('livewire.akun.status-aktivasi', [
            'totals' => ActivationReport::totals($all),
            'summary' => $report->summary($all),
            'classDialog' => $this->classDialog($all, $canReopen, $isAdmin),
            'openClassOnLoad' => $this->openClassOnLoad && $this->kelas !== '',
            'rows' => $filtered->take($this->limit)->map(fn (array $row): array => $row + [
                'action' => self::rowAction($row['state'], $row['frozenReason'], true, $canReopen, $isAdmin),
            ]),
            'filteredCount' => $filtered->count(),
            'hasMore' => $filtered->count() > $this->limit,
            'classes' => ActivationReport::classOptions()->all(),
            'states' => AccountControls::STATES,
            'frozen' => $this->frozenAccounts($isAdmin)->map(fn (array $account): array => $account + [
                'action' => self::rowAction(AccountControls::STATE_FROZEN, $account['reason'], $account['student'], $canReopen, $isAdmin),
            ]),
            'k7' => $report->initialPasswordAlerts(),
            'canReopen' => $canReopen,
            'canUnfreeze' => $isAdmin,
            'canSetWindow' => Gate::allows(Permission::SettingManage->value),
            'currentWindow' => app(AccountControls::class)->windowDays(),
            'target' => $target === null ? null : [
                'name' => (string) $target->name,
                'classroom' => $target->student?->classroom?->name,
            ],
            'filtersActive' => $this->daftarKelas !== '' || $this->status !== '' || trim($this->q) !== '',
        ]);
    }

    /**
     * Content of the class dialog: the students of `$kelas` out of the rows the page already loaded (no extra
     * query), the status tabs that have students, and one step of rows. Null while no class is chosen.
     *
     * @param  Collection<int, array<string, mixed>>  $all
     * @return array{name: string, total: int, tabs: list<array{key: string, label: string, count: int}>, rows: Collection<int, array<string, mixed>>, count: int, hasMore: bool}|null
     */
    private function classDialog(Collection $all, bool $canReopen, bool $canUnfreeze): ?array
    {
        if ($this->kelas === '') {
            return null;
        }

        $students = $all->where('classroomId', $this->kelas)->values();

        if ($students->isEmpty()) {
            $this->kelas = '';

            return null;
        }

        $this->kelasLimit = max(self::PAGE, min(self::MAX, $this->kelasLimit));
        $totals = ActivationReport::totals($students);

        if (! array_key_exists($this->kelasStatus, AccountControls::STATES)) {
            $this->kelasStatus = '';
        }

        $tabs = [['key' => '', 'label' => 'Semua', 'count' => $students->count()]];

        foreach (AccountControls::STATES as $key => $meta) {
            if ($totals[$key] > 0 || $this->kelasStatus === $key) {
                $tabs[] = ['key' => $key, 'label' => $meta['label'], 'count' => $totals[$key]];
            }
        }

        $shown = $this->kelasStatus === '' ? $students : $students->where('state', $this->kelasStatus)->values();

        return [
            'name' => (string) $students->first()['classroom'],
            'total' => $students->count(),
            'tabs' => $tabs,
            'rows' => $shown->take($this->kelasLimit)->map(fn (array $row): array => $row + [
                'action' => self::rowAction($row['state'], $row['frozenReason'], true, $canReopen, $canUnfreeze),
            ]),
            'count' => $shown->count(),
            'hasMore' => $shown->count() > $this->kelasLimit,
        ];
    }

    /** A classroom id that exists and has students (the only ones "Lihat" is offered for). */
    private static function knownClass(string $classroomId): bool
    {
        return $classroomId !== '' && ActivationReport::classOptions()->has($classroomId);
    }

    /** The dialog is closed (or about to be): drop its state so the address and a reload do not reopen it. */
    private function forgetClass(): void
    {
        $this->kelas = '';
        $this->kelasStatus = '';
        $this->kelasLimit = self::PAGE;
    }

    /**
     * Frozen accounts (K3/K8). Kesiswaan sees students only; Admin also staff (K8 can freeze any account).
     *
     * @return Collection<int, array{id: int, name: string, where: string, reason: ?string, since: ?CarbonImmutable, student: bool}>
     */
    private function frozenAccounts(bool $includeStaff): Collection
    {
        return User::query()
            ->whereNotNull('frozen_at')
            ->when(! $includeStaff, fn ($q) => $q->whereHas('student'))
            ->with('student.classroom:id,name')
            ->orderByDesc('frozen_at')
            ->limit(50)
            ->get(['id', 'name', 'status', 'frozen_at', 'frozen_reason'])
            ->map(fn (User $u): array => [
                'id' => (int) $u->getKey(),
                'name' => (string) $u->name,
                'where' => $u->student !== null ? (string) ($u->student->classroom?->name ?? 'Siswa') : 'Staf',
                'reason' => AccountControls::frozenReason($u),
                'since' => AccountControls::date($u, 'frozen_at'),
                'student' => $u->student !== null,
            ]);
    }

    /**
     * Which button a row gets: 'reopen' (K2: pending/expired, or frozen after failed logins), 'unfreeze'
     * (Admin, any freeze), 'admin' (frozen by "Bukan saya?" and the viewer is not Admin) or null.
     */
    private static function rowAction(string $state, ?string $frozenReason, bool $student, bool $canReopen, bool $canUnfreeze): ?string
    {
        $reopenable = $student && (in_array($state, [AccountControls::STATE_PENDING, AccountControls::STATE_EXPIRED], true)
            || ($state === AccountControls::STATE_FROZEN && $frozenReason === AccountControls::FROZEN_FAILED_ATTEMPTS));

        return match (true) {
            $reopenable && $canReopen => self::ACTION_REOPEN,
            $state === AccountControls::STATE_FROZEN && $canUnfreeze => self::ACTION_UNFREEZE,
            $state === AccountControls::STATE_FROZEN => 'admin',
            default => null,
        };
    }

    private function openAction(User $target, string $action): void
    {
        $this->resetErrorBag();
        $this->reset('reason');
        $this->targetId = (int) $target->getKey();
        $this->action = $action;
        $this->dispatch('sth-modal-open', id: 'aktivasi-aksi');
    }

    private function studentAccount(int $userId): User
    {
        /** @var User|null $user */
        $user = User::query()->whereHas('student')->find($userId);
        abort_if($user === null, 404);

        return $user;
    }

    private function account(int $userId): User
    {
        /** @var User|null $user */
        $user = User::query()->find($userId);
        abort_if($user === null, 404);

        return $user;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }

    protected function returnUrl(): string
    {
        // `kelas` is only set while the class dialog is in use (e.g. Admin "Buka pembekuan" from a dialog row).
        $query = array_filter(['kelas' => $this->kelas, 'kelas_daftar' => $this->daftarKelas, 'status' => $this->status, 'q' => $this->q]);

        return $this->returnTo.($query !== [] ? '?'.http_build_query($query) : '');
    }
}
