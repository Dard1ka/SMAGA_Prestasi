<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Pengaturan;

use App\Actions\Settings\UpdatePublicSettings;
use App\Livewire\Admin\Concerns\RequiresRecentPassword;
use App\Livewire\Publik\Data\PublicSettings;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Tab "Halaman publik" (W2-D addition to UI-MAP #24): search-engine indexing of the aggregate public pages and
 * certificate links (every team member named; no consent since D-162). Both off by default (D-38, D-39,
 * SECURITY §3m-7). Student pages always stay noindex.
 */
final class Publik extends Component
{
    use RequiresRecentPassword;

    public bool $indexing = false;

    public bool $certificates = false;

    public function mount(): void
    {
        Gate::authorize('update', Setting::class);
        $this->indexing = PublicSettings::indexingAllowed();
        $this->certificates = PublicSettings::certificatesAllowed();
    }

    public function save(UpdatePublicSettings $update): void
    {
        Gate::authorize('update', Setting::class);

        if (! $this->passwordRecentlyConfirmed()) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $update->handle($user, $this->indexing, $this->certificates);
        $this->dispatch('sth-toast', message: 'Pengaturan halaman publik tersimpan.', tone: 'success');
    }

    public function render(): View
    {
        return view('livewire.admin.pengaturan.publik');
    }

    protected function returnUrl(): string
    {
        return route('admin.pengaturan', ['tab' => 'publik']);
    }
}
