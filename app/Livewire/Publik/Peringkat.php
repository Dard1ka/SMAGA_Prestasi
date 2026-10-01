<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Enums\Bidang;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Publik · Peringkat kumulatif (UI-MAP #3, board `Peringkat`, D-09): modes siswa/kelas, filters bidang and
 * angkatan, alumni toggle (siswa mode). Data from App\Domain\Leaderboard\Ranker via PublicAchievements.
 * Public properties are the URL filters only; every value is normalised (invalid → default).
 */
final class Peringkat extends Component
{
    public const STEP = 20;

    public const MAX = 200;

    #[Url(except: 'siswa')]
    public string $mode = 'siswa';

    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $angkatan = '';

    #[Url(except: false)]
    public bool $alumni = false;

    #[Url(except: self::STEP)]
    public int $limit = self::STEP;

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if ($property !== 'limit') {
            $this->limit = self::STEP;
        }

        $this->normalize();
    }

    public function pilihMode(string $mode): void
    {
        $this->mode = $mode;
        $this->limit = self::STEP;
        $this->normalize();
    }

    public function tampilkanLebih(): void
    {
        $this->limit += self::STEP;
        $this->normalize();
    }

    public function render(): View
    {
        $this->normalize();

        $data = app(PublicAchievements::class);
        $rows = $data->ranking(
            $this->mode,
            Bidang::tryFrom($this->bidang),
            $this->angkatan !== '' ? (int) $this->angkatan : null,
            $this->alumni,
        );

        return view('livewire.publik.peringkat', [
            'total' => count($rows),
            'top' => array_slice($rows, 0, 3),
            'rest' => array_slice($rows, 3, max(0, $this->limit - 3)),
            'hasMore' => count($rows) > $this->limit && $this->limit < self::MAX,
            'bidangOptions' => Bidang::options(),
            'angkatanOptions' => $data->angkatanOptions(),
            'weights' => $data->weightInfo(),
        ])->layout('layouts::public', ['current' => 'peringkat', 'robots' => PublicSettings::robots()])
            ->title('Peringkat prestasi');
    }

    private function normalize(): void
    {
        $this->mode = in_array($this->mode, ['siswa', 'kelas'], true) ? $this->mode : 'siswa';

        if ($this->bidang !== '' && Bidang::tryFrom($this->bidang) === null) {
            $this->bidang = '';
        }

        if ($this->angkatan !== '' && ! array_key_exists($this->angkatan, app(PublicAchievements::class)->angkatanOptions())) {
            $this->angkatan = '';
        }

        if ($this->mode === 'kelas') {
            $this->alumni = false;
        }

        $this->limit = max(self::STEP, min(self::MAX, $this->limit));
    }
}
