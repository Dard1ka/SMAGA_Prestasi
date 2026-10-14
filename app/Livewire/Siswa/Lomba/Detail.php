<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Domain\Points\PointCalculator;
use App\Domain\Points\WeightTable;
use App\Enums\Participation;
use App\Enums\ResultType;
use App\Http\Controllers\FileController;
use App\Livewire\Siswa\Pendaftaran\Form;
use App\Models\Competition;
use App\Models\WeightVersion;
use App\Rules\ExternalUrl;
use App\Support\Sampul;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
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

    /** The "Daftar lomba" panel changed a registration: render again so "N siswa SMAGA sudah mendaftar" is current (QA6). */
    #[On(Form::CHANGED)]
    public function registrationChanged(): void {}

    public function render(): View
    {
        $competition = Competition::query()->with(['juknisFile', 'coverFile'])->findOrFail($this->competitionId);
        abort_unless(Gate::inspect('view', $competition)->allowed(), 404);

        $registrants = LombaPresenter::registrants([$competition->getKey()])[$competition->getKey()] ?? [];
        $window = LombaPresenter::window($competition);

        return view('livewire.siswa.lomba.detail', [
            'competition' => $competition,
            'window' => $window,
            'notice' => LombaPresenter::unpublishedNotice($competition),
            'registrants' => $registrants,
            'facts' => $this->facts($competition),
            'syarat' => $this->syarat($competition),
            'jadwal' => $this->jadwal($competition),
            'rumus' => $this->rumus($competition),
            // "(tiap anggota tim dapat poin penuh)" only where a team may register (FS1 QA: not on an individu lomba).
            'teamLomba' => $competition->participation !== null && $competition->participation !== Participation::Individu,
            'links' => $this->links($competition),
            'penyelenggara' => LombaPresenter::penyelenggara($competition),
            'paragraphs' => $this->paragraphs($competition->description),
            'poster' => $this->poster($competition),
        ])->title($competition->name);
    }

    /**
     * The lomba's own poster (D-118): `src` = the small picture for the cover (`files.cover`), the rest opens the
     * whole file in the preview popup ("Lihat poster"; `files.show`, audited like every other file link).
     * Null when there is no poster or this user would not get it: the cover then shows the bidang drawing.
     *
     * @return array{src: string, href: string, preview: ?string, previewType: ?string, name: string}|null
     */
    private function poster(Competition $competition): ?array
    {
        $src = Sampul::poster($competition);
        $file = $competition->coverFile;

        if ($src === null || $file === null) {
            return null;
        }

        return [
            'src' => $src,
            'href' => route('files.show', $file),
            'preview' => FileController::previewUrl($file),
            'previewType' => FileController::previewType($file),
            'name' => 'Poster '.$competition->name,
        ];
    }

    /** @return list<array{icon: string, label: string, value: string}> */
    private function facts(Competition $competition): array
    {
        return [
            // D-147: the three periods are named the same on every read screen.
            ['icon' => 'clock', 'label' => 'Pendaftaran', 'value' => LombaPresenter::pendaftaran($competition) ?? 'Menyusul'],
            ['icon' => 'calendar', 'label' => 'Pelaksanaan', 'value' => LombaPresenter::tanggal($competition)],
            ['icon' => 'trophy', 'label' => 'Tingkat', 'value' => $competition->level?->label() ?? 'Menyusul'],
            ['icon' => 'pin', 'label' => 'Tempat', 'value' => LombaPresenter::tempat($competition)],
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
            'Pendaftaran perlu persetujuan sekolah sebelum surat tugas dibuat.',
            $competition->juknis_file_id !== null ? 'Persyaratan lengkap ada di juknis (tab Dokumen).' : null,
        ]));
    }

    /**
     * The Jadwal tab (D-147): Pendaftaran, Pelaksanaan and Laporan hasil. No report deadline (D-151) and no "N hari
     * lagi" chips: the registration window chip stays on the head (LombaPresenter::window()). Empty when no date is set.
     *
     * @return list<array{label: string, value: string}>
     */
    private function jadwal(Competition $competition): array
    {
        $pendaftaran = LombaPresenter::pendaftaran($competition);
        $pelaksanaan = LombaPresenter::range($competition->starts_on, $competition->final_on);

        if ($pendaftaran === null && $pelaksanaan === null) {
            return [];
        }

        return [
            ['label' => 'Pendaftaran', 'value' => $pendaftaran ?? 'Menyusul'],
            ['label' => 'Pelaksanaan', 'value' => $pelaksanaan ?? 'Menyusul'],
            ['label' => 'Laporan hasil', 'value' => LombaPresenter::REPORT_LINE],
        ];
    }

    /** Points for Juara I, from the active weight version (display only, D-08). */
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
            $safe($competition->registration_url) ? ['label' => 'Link registrasi', 'note' => 'Daftar juga di situs penyelenggara bila juknis memintanya.', 'url' => $competition->registration_url, 'host' => ExternalUrl::host($competition->registration_url)] : null,
            $safe($competition->website_url) ? ['label' => 'Link resmi', 'note' => 'Info terbaru dari penyelenggara.', 'url' => $competition->website_url, 'host' => ExternalUrl::host($competition->website_url)] : null,
        ]));
    }

    /** @return list<string> */
    private function paragraphs(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R{2,}|\R/', (string) $text) ?: [])));
    }
}
