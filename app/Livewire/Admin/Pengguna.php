<?php

declare(strict_types=1);

namespace App\Livewire\Admin;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Sidebar "Pengguna & peran" (route admin.pengguna.index, UI-MAP §5): the same screen as the Pengaturan tab
 * (App\Livewire\Admin\Pengaturan\Pengguna) as its own page, so the topbar search (?q=) lands here.
 */
#[Title('Pengguna & peran')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Akun staf, peran, dan bantuan akun siswa.', 'search' => false])]
final class Pengguna extends Component
{
    public function mount(): void
    {
        Gate::authorize('viewAny', User::class);
    }

    public function render(): View
    {
        return view('livewire.admin.pengguna');
    }
}
