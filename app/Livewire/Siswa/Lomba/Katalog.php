<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Enums\Level;
use App\Models\Competition;
use App\Support\StaffRoleLabel;
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
 * its Detail lomba (CompetitionPolicy::view lets the submitter in). The catalog filters do not apply there; the name
 * search (?q=, the top bar search) does, so typing in the top bar never leaves the list unchanged.
 */
#[Layout('layouts::app')]
#[Title('Cari lomba')]
class Katalog extends Component
{
    use WithPagination;

    public const PER_PAGE = 9;

    public const STATUSES = ['dibuka' => 'Dibuka', 'segera' => 'Segera dibuka', 'ditutup' => 'Ditutup'];

    /** '' = the catalog, 'usulan' = the student's own proposals. */
    public const TABS = ['' => 'Katalog', 'usulan' => 'Usulan saya'];

    /**
     * The line under the page title per tab (FS1 final polish): "Usulan saya" lists lomba Kesiswaan has not checked
     * yet, so the catalog sentence would not be true there. The layout draws it on a page load (render()); a tab
     * picked on the page sends it to the top bar (event SUBTITLE_EVENT, resources/js/app.js).
     */
    public const SUBTITLES = [
        '' => 'Lomba di katalog sudah diperiksa Kesiswaan.',
        'usulan' => 'Usulan lombamu dan statusnya.',
    ];

    public const SUBTITLE_EVENT = 'sth-subtitle';

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

        if ($property === 'tab') {
            $this->sendSubtitle();
        }
    }

    public function pilihTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalise();
        $this->resetPage();
        $this->sendSubtitle();
    }

    public function pilihBidang(string $bidang): void
    {
        $this->bidang = $bidang;
        $this->normalise();
        $this->resetPage();
    }

    /** "Hapus pencarian" of an empty "Usulan saya": the catalog filters stay as they were. */
    public function hapusPencarian(): void
    {
        $this->reset('q');
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
            // The role that decided a rejected usulan is named next to its reason (StaffRoleLabel::forLomba(): Kesiswaan or Admin).
            $rows = $page->getCollection()->load('verifier.roles')->map(fn (Competition $c): array => self::proposalRow($c))->all();
        } else {
            $registrants = LombaPresenter::registrants($page->getCollection());
            $cards = $page->getCollection()
                ->map(fn (Competition $c): array => LombaPresenter::card($c, $registrants, route('siswa.lomba.show', $c)))
                ->all();
        }

        return view('livewire.siswa.lomba.katalog', [
            'tabs' => self::TABS,
            'proposals' => $proposals,
            // The count on the tab is every own lomba, whatever the search.
            'proposalCount' => $this->myProposals(search: false)->count(),
            'rows' => $rows,
            'page' => $page,
            'cards' => $cards,
            'bidangOptions' => ['' => 'Semua'] + Bidang::options(),
            'tingkatOptions' => collect(Level::cases())->reverse()->mapWithKeys(fn (Level $l) => [$l->value => $l->shortLabel()])->all(),
            'statusOptions' => self::STATUSES,
            'filtered' => $this->bidang !== '' || $this->tingkat !== '' || $this->status !== '' || $this->q !== '',
        ])->layoutData(['subtitle' => self::SUBTITLES[$this->tab]]);
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

    /** @return Builder<Competition> the student's own lomba, every status, oldest first (spec §5.2), by name like query() */
    private function myProposals(bool $search = true): Builder
    {
        return Competition::query()
            ->where('submitted_by', auth()->id())
            ->when($search && $this->q !== '', fn (Builder $q) => $q->where('name', 'like', '%'.addcslashes($this->q, '%_\\').'%'))
            ->oldest('created_at')
            ->orderBy('id');
    }

    /** @return array{id: string, name: string, organizer: ?string, status: string, tone: string, sent: string, note: ?string, noteLabel: string, href: string} */
    private static function proposalRow(Competition $competition): array
    {
        return [
            'id' => (string) $competition->getKey(),
            'name' => $competition->name,
            'organizer' => $competition->organizer_name,
            'status' => $competition->status->label(),
            'tone' => $competition->status->tone(),
            'sent' => CarbonImmutable::instance($competition->created_at)->locale('id')->translatedFormat('j M Y'),
            // A rejected usulan names the reason of the role that decided when one was written (D-103).
            'note' => $competition->status === CompetitionStatus::Ditolak ? $competition->verification_note : null,
            'noteLabel' => 'Catatan '.StaffRoleLabel::forLomba($competition->verifier).':',
            'href' => route('siswa.lomba.show', $competition),
        ];
    }

    /** The top bar is drawn once per page load: a tab picked on the page tells it its line (SUBTITLES). */
    private function sendSubtitle(): void
    {
        $this->dispatch(self::SUBTITLE_EVENT, text: self::SUBTITLES[$this->tab]);
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
