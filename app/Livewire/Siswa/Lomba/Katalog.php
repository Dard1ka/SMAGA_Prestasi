<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\Level;
use App\Models\Competition;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Siswa · Cari lomba (UI-MAP #10, board Katalog-Lomba). Published lomba only (D-27), filters in the
 * URL (bidang, tingkat, status pendaftaran, q — the top bar search posts ?q= here), 9 per page.
 * "Usulkan lomba" opens the page Siswa\Lomba\Usulkan (`siswa.lomba.usulkan`, D-145: the whole lomba, then its
 * registration; SubmitCompetition → menunggu_verifikasi).
 *
 * Tab "Usulan saya" (`?tab=usulan`, FS1 Paket B): the student's own lomba of every status, oldest first, each opening
 * its Detail lomba (CompetitionPolicy::view lets the submitter in). The catalog filters do not apply there.
 */
#[Layout('layouts::app', ['subtitle' => 'Semua lomba sudah diperiksa Kesiswaan.'])]
#[Title('Cari lomba')]
class Katalog extends Component
{
    use WithPagination;

    public const PER_PAGE = 9;

    public const STATUSES = ['dibuka' => 'Dibuka', 'segera' => 'Segera dibuka', 'ditutup' => 'Ditutup'];

    /** '' = the catalog, 'usulan' = the student's own proposals. */
    public const TABS = ['' => 'Katalog', 'usulan' => 'Usulan saya'];

    #[Url(except: '')]
    public string $tab = '';

    #[Url(except: '')]
    public string $bidang = '';

    #[Url(except: '')]
    public string $tingkat = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $q = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Competition::class);
        $this->normalise();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'bidang', 'tingkat', 'status', 'q'], true)) {
            $this->normalise();
            $this->resetPage();
        }
    }

    public function pilihTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalise();
        $this->resetPage();
    }

    public function pilihBidang(string $bidang): void
    {
        $this->bidang = $bidang;
        $this->normalise();
        $this->resetPage();
    }

    public function hapusFilter(): void
    {
        $this->reset('bidang', 'tingkat', 'status', 'q');
        $this->resetPage();
    }

    public function render(): View
    {
        $proposals = $this->tab === 'usulan';
        $query = $proposals ? $this->myProposals() : $this->query();
        $page = $query->paginate(self::PER_PAGE);

        // QA10: a stale ?page= (an old link, or fewer lomba since) showed "N lomba" above "Belum ada lomba di katalog"
        // without a pager. Show the last page instead.
        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->setPage($page->lastPage());
            $page = ($proposals ? $this->myProposals() : $this->query())->paginate(self::PER_PAGE);
        }

        $cards = [];
        $rows = [];

        if ($proposals) {
            $rows = $page->getCollection()->map(fn (Competition $c): array => self::proposalRow($c))->all();
        } else {
            $registrants = LombaPresenter::registrants($page->getCollection());
            $cards = $page->getCollection()
                ->map(fn (Competition $c): array => LombaPresenter::card($c, $registrants, route('siswa.lomba.show', $c)))
                ->all();
        }

        return view('livewire.siswa.lomba.katalog', [
            'tabs' => self::TABS,
            'proposals' => $proposals,
            'proposalCount' => $this->myProposals()->count(),
            'rows' => $rows,
            'page' => $page,
            'cards' => $cards,
            'bidangOptions' => ['' => 'Semua'] + Bidang::options(),
            'tingkatOptions' => collect(Level::cases())->reverse()->mapWithKeys(fn (Level $l) => [$l->value => $l->shortLabel()])->all(),
            'statusOptions' => self::STATUSES,
            'filtered' => $this->bidang !== '' || $this->tingkat !== '' || $this->status !== '' || $this->q !== '',
        ]);
    }

    /** @return Builder<Competition> */
    private function query(): Builder
    {
        $now = now();
        $closed = 'registration_closes_at IS NOT NULL AND registration_closes_at < ?';
        $upcoming = 'registration_opens_at IS NOT NULL AND registration_opens_at > ?';

        return Competition::query()
            ->published()
            ->when($this->bidang !== '', fn (Builder $q) => $q->where('bidang', $this->bidang))
            ->when($this->tingkat !== '', fn (Builder $q) => $q->where('level', $this->tingkat))
            ->when($this->status === 'dibuka', fn (Builder $q) => $q->openForRegistration($now))
            ->when($this->status === 'segera', fn (Builder $q) => $q->where('registration_opens_at', '>', $now))
            ->when($this->status === 'ditutup', fn (Builder $q) => $q->where('registration_closes_at', '<', $now))
            ->when($this->q !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($this->q, '%_\\').'%'))
            // Open first (closing soonest, no deadline last), then upcoming (opening soonest), then closed (most recent
            // first). QA10: upcoming lomba were mixed into the open ones and closed ones came oldest first.
            ->orderByRaw("CASE WHEN {$closed} THEN 2 WHEN {$upcoming} THEN 1 ELSE 0 END", [$now, $now])
            ->orderByRaw("CASE WHEN {$upcoming} THEN registration_opens_at END", [$now])
            ->orderByRaw('CASE WHEN registration_closes_at IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw("CASE WHEN {$closed} THEN registration_closes_at END DESC", [$now])
            ->orderBy('registration_closes_at')
            ->orderBy('name');
    }

    /** @return Builder<Competition> the student's own lomba, every status, oldest first (spec §5.2) */
    private function myProposals(): Builder
    {
        return Competition::query()
            ->where('submitted_by', auth()->id())
            ->oldest('created_at')
            ->orderBy('id');
    }

    /** @return array{id: string, name: string, organizer: ?string, status: string, tone: string, sent: string, note: ?string, href: string} */
    private static function proposalRow(Competition $competition): array
    {
        return [
            'id' => (string) $competition->getKey(),
            'name' => $competition->name,
            'organizer' => $competition->organizer_name,
            'status' => $competition->status->label(),
            'tone' => $competition->status->tone(),
            'sent' => CarbonImmutable::instance($competition->created_at)->locale('id')->translatedFormat('j M Y'),
            // A rejected usulan names Kesiswaan's reason when one was written (D-103).
            'note' => $competition->status === CompetitionStatus::Ditolak ? $competition->verification_note : null,
            'href' => route('siswa.lomba.show', $competition),
        ];
    }

    private function normalise(): void
    {
        $this->tab = array_key_exists($this->tab, self::TABS) ? $this->tab : '';
        $this->bidang = Bidang::tryFrom($this->bidang)?->value ?? '';
        $this->tingkat = Level::tryFrom($this->tingkat)?->value ?? '';
        $this->status = array_key_exists($this->status, self::STATUSES) ? $this->status : '';
        $this->q = mb_substr(trim($this->q), 0, 100);
    }
}
