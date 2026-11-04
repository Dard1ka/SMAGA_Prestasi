<?php

declare(strict_types=1);

namespace Tests\Feature\DesignSystem;

use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
| layouts::app as the Livewire 4 full-page layout (W1-D): title/subtitle from attributes, named slots
| forwarded from the page template into the top bar.
*/

#[Title('Beranda')]
#[Layout('layouts::app', ['role' => 'siswa', 'subtitle' => 'Rabu, 30 September 2026', 'user' => ['name' => 'Nadia Putri Anindya', 'meta' => 'Siswa · XI-4']])]
final class SthLayoutProbe extends Component
{
    public function render(): string
    {
        return <<<'BLADE'
            <div>
                <p>Halo, Nadia</p>
                <x-slot:actions>
                    <x-sth.button size="sm" href="#">Aksi halaman</x-sth.button>
                </x-slot:actions>
            </div>
        BLADE;
    }
}

it('renders a Livewire full-page component inside layouts::app', function (): void {
    $this->withoutVite()->withoutExceptionHandling();
    Route::livewire('/_lw-layout-probe', SthLayoutProbe::class)->middleware('web');

    $this->get('/_lw-layout-probe')
        ->assertOk()
        ->assertSee('<title>Beranda · SMAGA Talent Hub</title>', false)
        ->assertSee('Rabu, 30 September 2026')
        ->assertSee('Halo, Nadia')
        ->assertSee('Aksi halaman')
        ->assertSee('Nadia Putri Anindya')
        ->assertSee('aria-label="Menu utama"', false);
});
