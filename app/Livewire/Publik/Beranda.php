<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * Publik · Beranda prestasi (UI-MAP #1, board `Main`): hero, stat tiles, per-bidang tiles + chart per
 * tahun ajaran, top 5 of the cumulative ranking, latest verified results. No public properties: all data
 * goes to the view from render() (nothing in the Livewire snapshot).
 */
final class Beranda extends Component
{
    public function render(): View
    {
        $data = app(PublicAchievements::class);

        return view('livewire.publik.beranda', [
            'overview' => $data->overview(),
            'recent' => $data->recent(6),
            'top' => array_slice($data->ranking('siswa'), 0, 5),
        ])->layout('layouts::public', ['current' => 'beranda', 'robots' => PublicSettings::robots()])
            ->title('Prestasi siswa');
    }
}
