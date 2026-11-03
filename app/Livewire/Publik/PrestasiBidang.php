<?php

declare(strict_types=1);

namespace App\Livewire\Publik;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Livewire\Publik\Data\PublicAchievements;
use App\Livewire\Publik\Data\PublicSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Publik · Prestasi per bidang (UI-MAP #2, board `Prestasi-Bidang`): level 1 bidang buttons + chart per
 * tahun ajaran, level 2 students of the bidang, level 3 the selected student (same partial as
 * PrestasiSiswa). Public properties are the URL filters only; every value is normalised (invalid → default).
 */
final class PrestasiBidang extends Component
{
    /** Students shown per step: the full list of a bidang can be hundreds of rows (page weight on mobile). */
    public const STEP = 20;

    public const MAX = 200;

    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $tahun = '';

    #[Url(except: '')]
    public string $tingkat = '';

    #[Url(except: '')]
    public string $siswa = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        $this->normalize();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['bidang', 'tahun', 'tingkat'], true)) {
            $this->limit = self::STEP;
        }

        $this->normalize();
    }

    public function pilihBidang(string $bidang): void
    {
        if ($bidang !== $this->bidang) {
            $this->siswa = '';
            $this->limit = self::STEP;
        }

        $this->bidang = $bidang;
        $this->normalize();
    }

    public function tampilkanLebih(): void
    {
        $this->limit += self::STEP;
        $this->normalize();
    }

    public function pilihSiswa(string $slug): void
    {
        $this->siswa = $slug;
        $this->normalize();
    }

    public function tutupSiswa(): void
    {
        $this->siswa = '';
    }

    public function render(): View
    {
        $this->normalize();

        $data = $this->data();
        $bidang = Bidang::tryFrom($this->bidang) ?? $data->defaultBidang();
        $tahun = $this->tahun !== '' ? $this->tahun : null;
        $tingkat = $this->tingkat !== '' ? $this->tingkat : null;
        $summary = $data->bidangSummary($tahun, $tingkat);
        $students = $data->studentsInBidang($bidang, $tahun, $tingkat);

        return view('livewire.publik.prestasi-bidang', [
            'summary' => $summary,
            'selected' => collect($summary['bidang'])->firstWhere('value', $bidang->value),
            'years' => $summary['years'][$bidang->value] ?? [],
            'students' => array_slice($students, 0, $this->limit),
            'studentTotal' => count($students),
            'hasMore' => count($students) > $this->limit && $this->limit < self::MAX,
            'detail' => $this->siswa !== '' ? $data->studentDetail($this->siswa) : null,
            'yearOptions' => $data->schoolYearOptions(),
            'levelOptions' => $data->levelOptions(),
            'tingkatLabel' => $tingkat !== null ? Level::from($tingkat)->label() : null,
        ])->layout('layouts::public', [
            'current' => 'prestasi',
            'robots' => PublicSettings::robots(studentPage: $this->siswa !== ''),
        ])->title('Prestasi per bidang');
    }

    private function normalize(): void
    {
        $data = $this->data();

        $this->bidang = (Bidang::tryFrom($this->bidang) ?? $data->defaultBidang())->value;

        if ($this->tahun !== '' && ! array_key_exists($this->tahun, $data->schoolYearOptions())) {
            $this->tahun = '';
        }

        if ($this->tingkat !== '' && Level::tryFrom($this->tingkat) === null) {
            $this->tingkat = '';
        }

        if ($this->siswa !== '' && $data->studentDetail($this->siswa) === null) {
            $this->siswa = '';
        }

        $this->limit = max(self::STEP, min(self::MAX, $this->limit));
    }

    private function data(): PublicAchievements
    {
        return app(PublicAchievements::class);
    }
}
