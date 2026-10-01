<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Domain\Deadlines\ReportDeadline;
use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\Participation;
use App\Enums\ResultType;
use App\Models\Competition;
use App\Models\WeightVersion;
use App\Rules\ExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Siswa · Detail lomba (UI-MAP #11, board Detail-Lomba): banner, key facts, tabs Tentang / Persyaratan /
 * Jadwal / Dokumen, and the "Daftar lomba" panel (child Siswa\Pendaftaran\Form). Unpublished lomba:
 * only the submitter (and verifiers) may see it, everyone else gets 404 (CompetitionPolicy::view).
 */
#[Layout('layouts::app')]
class Detail extends Component
{
    #[Locked]
    public string $competitionId = '';

    public function mount(Competition $competition): void
    {
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $this->competitionId = $competition->getKey();
    }

    public function render(): View
    {
        $competition = Competition::query()->with('juknisFile')->findOrFail($this->competitionId);
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $registrants = LombaPresenter::registrants([$competition->getKey()])[$competition->getKey()] ?? [];
        $window = LombaPresenter::window($competition);

        return view('livewire.siswa.lomba.detail', [
            'competition' => $competition,
            'window' => $window,
            'registrants' => $registrants,
            'facts' => $this->facts($competition),
            'syarat' => $this->syarat($competition),
            'jadwal' => $this->jadwal($competition),
            'rumus' => $this->rumus($competition),
            'links' => $this->links($competition),
            'penyelenggara' => LombaPresenter::penyelenggara($competition),
            'paragraphs' => $this->paragraphs($competition->description),
        ])->title($competition->name);
    }

    /** @return list<array{icon: string, label: string, value: string}> */
    private function facts(Competition $competition): array
    {
        return [
            ['icon' => 'clock', 'label' => 'Batas daftar', 'value' => LombaPresenter::date($competition->registration_closes_at)],
            ['icon' => 'calendar', 'label' => 'Tanggal lomba', 'value' => LombaPresenter::tanggal($competition)],
            ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => $competition->level?->label() ?? 'Menyusul'],
            ['icon' => 'pin', 'label' => 'Pelaksanaan', 'value' => LombaPresenter::tempat($competition)],
            ['icon' => 'users', 'label' => 'Peserta', 'value' => match ($competition->participation) {
                Participation::Tim => $competition->max_team_size ? 'Tim, maks '.$competition->max_team_size : 'Tim',
                Participation::Keduanya => $competition->max_team_size ? 'Individu atau tim (maks '.$competition->max_team_size.')' : 'Individu atau tim',
                Participation::Individu => 'Individu',
                default => 'Menyusul',
            }],
        ];
    }

    /** @return list<string> */
    private function syarat(Competition $competition): array
    {
        $max = $competition->max_team_size;

        return array_values(array_filter([
            'Siswa aktif SMA Negeri 3 Semarang.',
            match ($competition->participation) {
                Participation::Individu => 'Peserta mendaftar sendiri (individu).',
                Participation::Tim => $max ? "Tim berisi 2–{$max} siswa SMAGA, termasuk ketua." : 'Tim berisi minimal 2 siswa SMAGA, termasuk ketua.',
                Participation::Keduanya => $max ? "Boleh individu atau tim (2–{$max} siswa, termasuk ketua)." : 'Boleh individu atau tim.',
                default => null,
            },
            'Didampingi satu guru pembimbing.',
            $competition->participation === Participation::Individu ? null : 'Setiap anggota tim mengonfirmasi lewat akunnya sendiri.',
            'Pendaftaran perlu persetujuan Kepala Sekolah sebelum surat tugas dibuat.',
            $competition->juknis_file_id !== null ? 'Persyaratan lengkap ada di juknis (tab Dokumen).' : null,
        ]));
    }

    /** @return list<array{date: string, label: string, chip: ?string, tone: string}> */
    private function jadwal(Competition $competition): array
    {
        $deadline = app(ReportDeadline::class);
        $deadlineDays = $deadline->days();
        $final = $competition->final_on ?? $competition->starts_on;

        $rows = array_values(array_filter([
            $competition->registration_opens_at ? [$competition->registration_opens_at, 'Pendaftaran dibuka'] : null,
            $competition->registration_closes_at ? [$competition->registration_closes_at, 'Batas pendaftaran'] : null,
            $competition->starts_on ? [$competition->starts_on, 'Lomba dimulai'] : null,
            $competition->final_on && (! $competition->starts_on || ! $competition->final_on->isSameDay($competition->starts_on))
                ? [$competition->final_on, 'Final dan pengumuman'] : null,
            $final ? [$deadline->dueDate($final), "Batas laporan hasil ({$deadlineDays} hari setelah final)"] : null,
        ]));

        $today = CarbonImmutable::today();
        $nextMarked = false;

        return array_map(function (array $row) use ($today, &$nextMarked): array {
            [$date, $label] = $row;
            $days = LombaPresenter::daysUntil($date, $today);

            if ($days < 0) {
                return ['date' => LombaPresenter::date($date), 'label' => $label, 'chip' => 'Selesai', 'tone' => 'green'];
            }

            if (! $nextMarked) {
                $nextMarked = true;

                return ['date' => LombaPresenter::date($date), 'label' => $label, 'chip' => $days === 0 ? 'Hari ini' : "{$days} hari lagi", 'tone' => 'blue'];
            }

            return ['date' => LombaPresenter::date($date), 'label' => $label, 'chip' => null, 'tone' => 'gray'];
        }, $rows);
    }

    /** Points if the team wins Juara I, from the active weight version (display only, D-08). */
    private function rumus(Competition $competition): ?array
    {
        if ($competition->level === null || $competition->weight_category === null) {
            return null;
        }

        $table = WeightVersion::active()?->toWeightTable() ?? WeightTable::defaults();
        $breakdown = app(PointCalculator::class)->breakdown($table, $competition->level, ResultType::Juara1, $competition->weight_category);

        return [
            'tingkat' => $breakdown->levelWeight,
            'capaian' => $breakdown->resultWeight,
            'penyelenggara' => $breakdown->organizerWeight,
            'total' => $breakdown->points,
        ];
    }

    /** External links (never fetched): shown with their host, rel="nofollow noopener noreferrer ugc". */
    private function links(Competition $competition): array
    {
        $safe = fn (?string $url): bool => is_string($url) && ExternalUrl::passes($url);

        return array_values(array_filter([
            $safe($competition->registration_url) ? ['label' => 'Link registrasi resmi', 'note' => 'Daftar juga di situs penyelenggara bila juknis memintanya.', 'url' => $competition->registration_url, 'host' => ExternalUrl::host($competition->registration_url)] : null,
            $safe($competition->website_url) ? ['label' => 'Website resmi', 'note' => 'Info terbaru dari penyelenggara.', 'url' => $competition->website_url, 'host' => ExternalUrl::host($competition->website_url)] : null,
        ]));
    }

    /** @return list<string> */
    private function paragraphs(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}|\R/', (string) $text) ?: [])));
    }
}
