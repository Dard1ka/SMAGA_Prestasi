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
 */
#[Title('Status aktivasi')]
#[Layout('layouts::app', ['subtitle' => 'Aktivasi akun siswa per kelas.'])]
final class StatusAktivasi extends Component
{
    use RequiresRecentPassword;

    public const PAGE = 100;

    public const ACTION_REOPEN = 'buka';

    public const ACTION_UNFREEZE = 'cair';

    #[Url(as: 'kelas', except: '')]
    public string $kelas = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Locked]
    public int $limit = self::PAGE;

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
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['kelas', 'status', 'q'], true)) {
            $this->limit = self::PAGE;
        }
    }

    public function setStatus(string $status): void
    {
        $this->status = array_key_exists($status, AccountControls::STATES) ? $status : '';
        $this->limit = self::PAGE;
    }

    public function showClass(string $classroomId): void
    {
        $this->kelas = $classroomId;
        $this->limit = self::PAGE;
    }

    public function resetFilters(): void
    {
        $this->reset('kelas', 'status', 'q');
        $this->limit = self::PAGE;
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE;
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

        if (! $this->passwordRecentlyConfirmed()) {
            return null;
        }

        $rows = $report->rows($this->kelas ?: null, $this->status ?: null, $this->q);

        activity('account')
            ->causedBy($this->actor())
            ->event('account.activation_report_exported')
            ->withProperties(['filters' => array_filter(['kelas' => $this->kelas, 'status' => $this->status, 'q' => $this->q !== '' ? 'ya' : '']), 'rows' => $rows->count()])
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
        $all = $report->rows();
        $filtered = $report->rows($this->kelas ?: null, $this->status ?: null, $this->q);
        $isAdmin = Gate::allows(Permission::UserResetPassword->value);
        $canReopen = Gate::allows(Permission::StudentImport->value);
        $target = $this->targetId !== null ? User::query()->with('student.classroom:id,name')->find($this->targetId, ['id', 'name']) : null;

        return view('livewire.akun.status-aktivasi', [
            'totals' => ActivationReport::totals($all),
            'summary' => $report->summary($all),
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
            'filtersActive' => $this->kelas !== '' || $this->status !== '' || trim($this->q) !== '',
        ]);
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
        $query = array_filter(['kelas' => $this->kelas, 'status' => $this->status, 'q' => $this->q]);

        return $this->returnTo.($query !== [] ? '?'.http_build_query($query) : '');
    }
}
