<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Actions\Roles\ApproveRoleChange;
use App\Actions\Roles\CancelRoleChange;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Models\RoleChangeRequest;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * "Perubahan peran menunggu" panel inside Admin › Pengguna & peran (backlog #13, owner B5): pending privileged
 * role changes with "Setujui" (a second Admin, not the requester, not the account itself) and "Batalkan"
 * (any Admin, reason required), plus the latest finished ones. Both actions re-authorize through
 * RoleChangeRequestPolicy inside the Actions and need a password confirmation from the last 15 minutes
 * (SECURITY §3m-9). Refreshes when the parent dispatches `role-change-requested`.
 */
final class RoleChangeRequests extends Component
{
    use RequiresRecentPassword;

    #[Locked]
    public ?string $cancelId = null;

    public string $cancelReason = '';

    /** Page to come back to after a password confirmation. */
    #[Locked]
    public string $returnTo = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', RoleChangeRequest::class);
        $this->returnTo = request()->routeIs('admin.pengaturan')
            ? route('admin.pengaturan', ['tab' => 'pengguna'])
            : route('admin.pengguna.index');
    }

    #[On('role-change-requested')]
    public function refreshList(): void
    {
        // Re-render only.
    }

    public function approve(string $requestId, ApproveRoleChange $approve): void
    {
        $request = $this->find($requestId);
        Gate::authorize('approve', $request);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $applied = $approve->handle($this->actor(), $request);

        $this->dispatch('sth-toast',
            message: $applied ? 'Disetujui. Peran sudah diubah, pengguna perlu login ulang.' : 'Disetujui, tetapi akun tidak lagi memenuhi syarat. Permintaan dibatalkan otomatis.',
            tone: $applied ? 'success' : 'error',
        );
    }

    public function openCancel(string $requestId): void
    {
        $request = $this->find($requestId);
        Gate::authorize('cancel', $request);

        $this->resetErrorBag();
        $this->cancelId = (string) $request->getKey();
        $this->reset('cancelReason');
        $this->dispatch('sth-modal-open', id: 'batal-peran');
    }

    public function cancel(CancelRoleChange $cancel): void
    {
        $request = $this->find((string) $this->cancelId);
        Gate::authorize('cancel', $request);

        $this->validate(['cancelReason' => ['required', 'string', 'max:500']], [], ['cancelReason' => 'alasan']);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        $cancel->handle($this->actor(), $request, $this->cancelReason);
        $this->reset('cancelId', 'cancelReason');
        $this->dispatch('sth-modal-close', id: 'batal-peran');
        $this->dispatch('sth-toast', message: 'Perubahan peran dibatalkan.', tone: 'success');
    }

    public function render(): View
    {
        $actor = $this->actor();
        $with = ['user:id,name,username,deleted_at', 'requester:id,name,deleted_at', 'approver:id,name,deleted_at', 'canceller:id,name,deleted_at'];

        $pending = RoleChangeRequest::query()->pending()->with($with)->oldest('created_at')->get();

        return view('livewire.admin.role-change-requests', [
            'pending' => $pending->map(fn (RoleChangeRequest $r): array => [
                'request' => $r,
                'canApprove' => $actor->can('approve', $r),
                'canCancel' => $actor->can('cancel', $r),
            ]),
            'recent' => RoleChangeRequest::query()
                ->where(fn ($q) => $q->whereNotNull('applied_at')->orWhereNotNull('cancelled_at'))
                ->where('updated_at', '>=', now()->subDays(30))
                ->with($with)
                ->latest('updated_at')
                ->limit(5)
                ->get(),
        ]);
    }

    private function find(string $id): RoleChangeRequest
    {
        /** @var RoleChangeRequest $request */
        $request = RoleChangeRequest::query()->findOrFail($id);

        return $request;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    protected function returnUrl(): string
    {
        return $this->returnTo !== '' ? $this->returnTo : route('admin.pengguna.index');
    }
}
