<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Publik · Prestasi seorang siswa (UI-MAP #2a): `/prestasi/siswa/{slug}` with the random public_slug.
 * Only students with publish consent; anything else is a 404. Always noindex (SECURITY §3m-7).
 */
final class PrestasiSiswa extends Component
{
    #[Locked]
    public string $slug = '';

    public function mount(string $slug): void
    {
        abort_if(app(PublicAchievements::class)->studentDetail($slug) === null, 404);

        $this->slug = $slug;
    }

    public function render(): View
    {
        $detail = app(PublicAchievements::class)->studentDetail($this->slug);

        abort_if($detail === null, 404);

        return view('livewire.publik.prestasi-siswa', ['detail' => $detail])->layout('layouts::public', ['current' => 'prestasi', 'robots' => PublicSettings::robots(studentPage: true)])
            ->title($detail['name']);
    }
}
