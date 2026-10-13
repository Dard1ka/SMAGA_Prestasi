<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\Setting;
use App\Models\User;
use App\Models\WeightVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Admin › Pengaturan (mockup Admin-Pengaturan.dc.html, UI-MAP #24): vertical tabs, each a child component
 * App\Livewire\Admin\Pengaturan\{Bobot,BatasArsip,Surat,Pengguna,Email,Publik}. The tab lives in `?tab=`
 * (links, server state); every child authorizes itself again. Route: admin.pengaturan (password.confirm).
 */
#[Title('Pengaturan')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Aturan poin, arsip, surat, dan akun.', 'search' => false])]
final class Pengaturan extends Component
{
    public const TABS = [
        'bobot' => ['label' => 'Bobot poin', 'icon' => 'award'],
        'batas' => ['label' => 'Batas & arsip', 'icon' => 'clock'],
        'surat' => ['label' => 'Surat & tanda tangan', 'icon' => 'file'],
        'pengguna' => ['label' => 'Pengguna & peran', 'icon' => 'users'],
        'email' => ['label' => 'Email', 'icon' => 'mail'],
        'publik' => ['label' => 'Halaman publik', 'icon' => 'eye'],
    ];

    #[Url(as: 'tab', history: true)]
    public string $tab = 'bobot';

    public function mount(): void
    {
        Gate::authorize('viewAny', Setting::class);
        $this->tab = $this->normalise($this->tab);
    }

    public function render(): View
    {
        $available = $this->availableTabs();
        $this->tab = array_key_exists($this->tab, $available) ? $this->tab : (string) array_key_first($available);

        return view('livewire.admin.pengaturan', [
            'tabs' => $available,
        ]);
    }

    /** @return array<string, array{label: string, icon: string}> */
    private function availableTabs(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $can = [
            'bobot' => $user->can('viewAny', WeightVersion::class),
            'batas' => $user->can('update', Setting::class),
            'surat' => $user->can('update', Setting::class) || $user->can('manageSignature', Setting::class),
            'pengguna' => $user->can('viewAny', User::class),
            'email' => $user->can('update', Setting::class),
            'publik' => $user->can('update', Setting::class),
        ];

        return array_filter(self::TABS, fn (string $key): bool => $can[$key], ARRAY_FILTER_USE_KEY);
    }

    private function normalise(string $tab): string
    {
        return array_key_exists($tab, self::TABS) ? $tab : 'bobot';
    }
}
