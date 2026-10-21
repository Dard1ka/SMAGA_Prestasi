<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Publik · Pemenang per lomba (UI-MAP #2b, D-31): the verified results of one lomba grouped by capaian,
 * best first, with the same naming rule as the other public pages. No achievements → 404.
 */
final class PemenangLomba extends Component
{
    #[Locked]
    public string $competition = '';

    public function mount(string $competition): void
    {
        abort_if(app(PublicAchievements::class)->winners($competition) === null, 404);

        $this->competition = strtolower($competition);
    }

    public function render(): View
    {
        $winners = app(PublicAchievements::class)->winners($this->competition);

        abort_if($winners === null, 404);

        return view('livewire.publik.pemenang-lomba', ['winners' => $winners])
            ->layout('layouts::public', ['current' => 'prestasi', 'robots' => PublicSettings::robots()])
            ->title($winners['lomba']);
    }
}
