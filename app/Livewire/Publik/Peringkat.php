<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Domain\Leaderboard\RankingScope;
use App\Enums\Bidang;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Publik · Peringkat kumulatif (UI-MAP #3, board `Peringkat`, D-09): modes siswa/kelas, filters bidang and
 * angkatan, and who is shown (`status`: siswa aktif = default, alumni only, or semua; lead 2026-10-04, replaces the
 * "Tampilkan alumni" checkbox). Data from App\Domain\Leaderboard\Ranker via PublicAchievements.
 * Public properties are the URL filters only; every value is normalised (invalid → default).
 *
 * Alumni have no class: with status "alumni" the class ranking is not offered (the Kelas button is disabled and
 * `?mode=kelas&status=alumni` falls back to the student list); in Kelas mode the status choice is kept but hidden,
 * so it is back when the visitor returns to Siswa. The angkatan choices follow the shown group, so a choice that
 * no longer exists (an alumni year while alumni are hidden) is cleared instead of giving an empty list.
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

    #[Url(except: 'aktif')]
    public string $status = 'aktif';

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
        // Alumni have no class: the class ranking stays closed while only alumni are shown.
        if ($mode === 'kelas' && ! RankingScope::fromValue($this->status)->hasClasses()) {
            return;
        }

        $this->mode = $mode;
        $this->limit = self::STEP;
        $this->normalize();
    }

    public function pilihStatus(string $status): void
    {
        $this->status = $status;
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
        $scope = RankingScope::fromValue($this->status);
        $rows = $data->ranking(
            $this->mode,
            Bidang::tryFrom($this->bidang),
            $this->angkatan !== '' ? (int) $this->angkatan : null,
            $scope,
        );

        return view('livewire.publik.peringkat', [
            'total' => count($rows),
            'top' => array_slice($rows, 0, 3),
            'rest' => array_slice($rows, 3, max(0, $this->limit - 3)),
            'hasMore' => count($rows) > $this->limit && $this->limit < self::MAX,
            'bidangOptions' => Bidang::options(),
            'angkatanOptions' => $data->angkatanOptions($scope, $this->mode),
            'statusOptions' => RankingScope::options(),
            'kelasAvailable' => $scope->hasClasses(),
            // What one row is called in the caption and the footer ("Menampilkan 4 dari 4 alumni").
            'noun' => match (true) {
                $this->mode === 'kelas' => 'kelas',
                $scope === RankingScope::Alumni => 'alumni',
                default => 'siswa',
            },
            'note' => $this->note($scope),
            'weights' => $data->weightInfo(),
        ])->layout('layouts::public', ['current' => 'peringkat', 'robots' => PublicSettings::robots()])
            ->title('Peringkat prestasi');
    }

    /** One short line that says who is in the list (nothing for the default view). */
    private function note(RankingScope $scope): ?string
    {
        return match (true) {
            $this->mode === 'kelas' => 'Peringkat kelas hanya menghitung siswa aktif. Alumni tidak punya kelas.',
            $scope === RankingScope::Alumni => 'Hanya alumni yang punya prestasi. Peringkat kelas tidak ada untuk alumni.',
            $scope === RankingScope::All => 'Siswa aktif dan alumni digabung dalam satu peringkat.',
            default => null,
        };
    }

    private function normalize(): void
    {
        $this->mode = in_array($this->mode, ['siswa', 'kelas'], true) ? $this->mode : 'siswa';

        $scope = RankingScope::fromValue($this->status);
        $this->status = $scope->value;

        if (! $scope->hasClasses()) {
            $this->mode = 'siswa';
        }

        if ($this->bidang !== '' && Bidang::tryFrom($this->bidang) === null) {
            $this->bidang = '';
        }

        if ($this->angkatan !== '' && ! array_key_exists($this->angkatan, app(PublicAchievements::class)->angkatanOptions($scope, $this->mode))) {
            $this->angkatan = '';
        }

        $this->limit = max(self::STEP, min(self::MAX, $this->limit));
    }
}
