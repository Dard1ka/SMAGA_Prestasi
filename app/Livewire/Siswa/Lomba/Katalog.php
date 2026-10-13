<?php

declare(strict_types=1);

namespace App\Livewire\Siswa\Lomba;

use App\Enums\Bidang;
use App\Enums\Level;
use App\Models\Competition;
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
 */
#[Layout('layouts::app', ['subtitle' => 'Semua lomba sudah diperiksa Kesiswaan.'])]
#[Title('Cari lomba')]
class Katalog extends Component
{
    use WithPagination;

    public const PER_PAGE = 9;

    public const STATUSES = ['dibuka' => 'Dibuka', 'segera' => 'Segera dibuka', 'ditutup' => 'Ditutup'];

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
        if (in_array($property, ['bidang', 'tingkat', 'status', 'q'], true)) {
            $this->normalise();
            $this->resetPage();
        }
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
        $page = $this->query()->paginate(self::PER_PAGE);

        // QA10: a stale ?page= (an old link, or fewer lomba since) showed "N lomba" above "Belum ada lomba di katalog"
        // without a pager. Show the last page instead.
        if ($page->isEmpty() && $page->currentPage() > 1) {
            $this->setPage($page->lastPage());
            $page = $this->query()->paginate(self::PER_PAGE);
        }

        $registrants = LombaPresenter::registrants($page->getCollection());
        $cards = $page->getCollection()
            ->map(fn (Competition $c): array => LombaPresenter::card($c, $registrants, route('siswa.lomba.show', $c)))
            ->all();

        return view('livewire.siswa.lomba.katalog', [
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

    private function normalise(): void
    {
        $this->bidang = Bidang::tryFrom($this->bidang)?->value ?? '';
        $this->tingkat = Level::tryFrom($this->tingkat)?->value ?? '';
        $this->status = array_key_exists($this->status, self::STATUSES) ? $this->status : '';
        $this->q = mb_substr(trim($this->q), 0, 100);
    }
}
