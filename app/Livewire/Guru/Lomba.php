<?php

declare(strict_types=1);

namespace App\Livewire\Guru;

use App\Enums\Bidang;
use App\Enums\CompetitionStatus;
use App\Livewire\Siswa\Lomba\LombaPresenter;
use App\Models\Competition;
use App\Models\File;
use App\Models\RegistrationMember;
use App\Models\User;
use App\Rules\ExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Guru · Lomba (UI-MAP §4e `guru.lomba.index`, backlog #3, owner B4). Tab "Katalog": published lomba
 * (read-only, detail drawer via `?lihat=<ulid>`); tab "Upload saya": lomba info this guru uploaded with
 * W2-A1's Lomba\UsulanForm → SubmitCompetition → menunggu_verifikasi until Kesiswaan publishes or rejects
 * it (D-27). Only the guru's own uploads are listed; a lomba the guru may not view (CompetitionPolicy) → 404.
 */
#[Title('Lomba')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Katalog lomba sekolah dan info lomba yang di-upload.', 'search' => false])]
final class Lomba extends Component
{
    public const STEP = 12;

    private const TABS = ['katalog', 'unggahan'];

    #[Url(as: 'tab', except: 'katalog')]
    public string $tab = 'katalog';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'bidang', except: '')]
    public string $bidang = '';

    /** Competition shown in the detail drawer (ULID); authorized on every render. */
    #[Url(as: 'lihat', except: '')]
    public string $lihat = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        Gate::authorize('viewAny', Competition::class);
        $this->normalize();
        $this->viewable($this->lihat);
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['tab', 'q', 'bidang'], true)) {
            $this->limit = self::STEP;
        }

        $this->normalize();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->limit = self::STEP;
        $this->normalize();
    }

    public function showMore(): void
    {
        $this->limit += self::STEP;
    }

    /** Lomba\UsulanForm sent an upload: show it in "Upload saya". */
    #[On('usulan-terkirim')]
    public function afterUpload(): void
    {
        $this->setTab('unggahan');
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Competition::class);
        $this->normalize();

        $counts = [
            'katalog' => Competition::query()->published()->count(),
            'unggahan' => $this->uploadsQuery()->count(),
            'menunggu' => $this->uploadsQuery()->where('status', CompetitionStatus::MenungguVerifikasi)->count(),
        ];

        $isCatalog = $this->tab === 'katalog';
        $query = $isCatalog ? $this->catalogQuery() : $this->uploadsQuery()->latest('created_at')->orderBy('id');
        $total = (clone $query)->count();
        $items = $query->limit($this->limit)->get();

        return view('livewire.guru.lomba', [
            'counts' => $counts,
            'isCatalog' => $isCatalog,
            'total' => $total,
            'hasMore' => $total > $this->limit,
            'cards' => $isCatalog ? $this->cards($items) : [],
            'uploads' => $isCatalog ? [] : $items->map(fn (Competition $c): array => $this->uploadRow($c))->all(),
            'canUpload' => Gate::allows('create', Competition::class),
            'bidangOptions' => Bidang::options(),
            'detail' => $this->lihat !== '' ? $this->detail($this->viewable($this->lihat)) : null,
            'closeUrl' => route('guru.lomba.index', array_filter(['tab' => $this->tab !== 'katalog' ? $this->tab : null])),
        ]);
    }

    /** @return Builder<Competition> */
    private function catalogQuery(): Builder
    {
        $term = trim($this->q);

        return Competition::query()
            ->published()
            ->when($this->bidang !== '', fn (Builder $q) => $q->where('bidang', $this->bidang))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', $this->like($term))
                ->orWhere('organizer_name', 'like', $this->like($term))))
            // Open registrations first (nearest deadline), then the rest by newest final date.
            ->orderByRaw('CASE WHEN registration_closes_at >= ? THEN 0 ELSE 1 END', [now()])
            ->orderBy('registration_closes_at')
            ->orderByDesc('final_on')
            ->orderBy('id');
    }

    /** @return Builder<Competition> this guru's own uploads (D-27 queue states incl. ditolak) */
    private function uploadsQuery(): Builder
    {
        $term = trim($this->q);

        return Competition::query()
            ->where('submitted_by', $this->user()->getKey())
            ->when($term !== '', fn (Builder $q) => $q->where('name', 'like', $this->like($term)));
    }

    /**
     * @param  EloquentCollection<int, Competition>  $competitions
     * @return list<array<string, mixed>>
     */
    private function cards(EloquentCollection $competitions): array
    {
        $registrants = LombaPresenter::registrants($competitions);

        return $competitions->map(fn (Competition $c): array => LombaPresenter::card(
            $c,
            $registrants,
            route('guru.lomba.index', array_filter(['q' => $this->q ?: null, 'bidang' => $this->bidang ?: null, 'lihat' => $c->getKey()])),
        ))->values()->all();
    }

    /** @return array<string, mixed> */
    private function uploadRow(Competition $competition): array
    {
        $status = $competition->status;

        return [
            'id' => (string) $competition->getKey(),
            'name' => $competition->name,
            'organizer' => $competition->organizer_name,
            'sent' => self::date($competition->created_at),
            'status' => $status->label(),
            'tone' => $status->tone(),
            'checked' => $competition->verified_at !== null ? self::date($competition->verified_at) : null,
            'note' => $status === CompetitionStatus::Ditolak ? $competition->verification_note : null,
            'href' => route('guru.lomba.index', ['tab' => 'unggahan', 'lihat' => $competition->getKey()]),
        ];
    }

    /**
     * The lomba in the drawer: published, or the guru's own upload (CompetitionPolicy::view); else 404.
     */
    private function viewable(string $id): ?Competition
    {
        if ($id === '') {
            return null;
        }

        $competition = preg_match('/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/', $id) === 1
            ? Competition::query()->with('juknisFile')->find($id)
            : null;

        abort_unless($competition !== null && Gate::allows('view', $competition), 404);

        return $competition;
    }

    /** @return array<string, mixed>|null */
    private function detail(?Competition $competition): ?array
    {
        if ($competition === null) {
            return null;
        }

        $window = LombaPresenter::window($competition);
        $website = is_string($competition->website_url) && ExternalUrl::passes($competition->website_url) ? $competition->website_url : null;
        $juknis = $competition->juknisFile;

        // Only students in the team: an invitation nobody answered yet is not a binaan (QA10).
        $binaan = RegistrationMember::query()
            ->whereNotNull('confirmed_at')
            ->whereNull('declined_at')
            ->whereHas('registration', fn (Builder $r) => $r
                ->where('competition_id', $competition->getKey())
                ->where('mentor_id', $this->user()->getKey()))
            ->with(['student:id,user_id,classroom_id', 'student.user:id,name', 'student.classroom:id,name', 'registration:id'])
            ->get()
            ->map(fn (RegistrationMember $m): array => [
                'name' => $m->student?->user?->name ?? 'Siswa',
                'kelas' => $m->student?->classroom?->name,
                'href' => route('guru.binaan.show', $m->registration_id),
            ])->all();

        return [
            'id' => (string) $competition->getKey(),
            'name' => $competition->name,
            'bidang' => $competition->bidang,
            'status' => $competition->status,
            'published' => $competition->isPublished(),
            'window' => $window,
            'description' => $competition->description,
            'facts' => array_values(array_filter([
                ['label' => 'Penyelenggara', 'value' => LombaPresenter::penyelenggara($competition)],
                ['label' => 'Jenis penyelenggara', 'value' => $competition->organizer_type?->label()],
                ['label' => 'Status ajang', 'value' => $competition->event_status?->label()],
                ['label' => 'Tingkat', 'value' => $competition->level?->label()],
                ['label' => 'Peserta', 'value' => $competition->participation !== null ? LombaPresenter::peserta($competition) : null],
                ['label' => 'Batas daftar', 'value' => $competition->registration_closes_at !== null ? self::date($competition->registration_closes_at) : null],
                ['label' => 'Tanggal lomba', 'value' => LombaPresenter::tanggal($competition) ?: null],
                ['label' => 'Tempat', 'value' => $competition->location],
            ], fn (array $fact): bool => is_string($fact['value']) && $fact['value'] !== '')),
            'website' => $website,
            'websiteHost' => $website !== null ? ExternalUrl::host($website) : null,
            'juknis' => $juknis instanceof File && Gate::allows('view', $juknis)
                ? ['href' => route('files.show', $juknis), 'name' => $juknis->original_name, 'size' => LombaPresenter::fileSize((int) $juknis->size)]
                : null,
            'binaan' => $binaan,
            'note' => $competition->status === CompetitionStatus::Ditolak && (int) $competition->submitted_by === (int) $this->user()->getKey()
                ? ($competition->verification_note ?? 'Kesiswaan tidak menulis alasan.')
                : null,
        ];
    }

    private static function date(?\DateTimeInterface $date): string
    {
        return $date === null ? '–' : CarbonImmutable::instance($date)->locale('id')->translatedFormat('j M Y');
    }

    private function like(string $term): string
    {
        return '%'.addcslashes(mb_substr($term, 0, 100), '%_\\').'%';
    }

    private function normalize(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'katalog';
        $this->q = mb_substr(trim($this->q), 0, 100);
        $this->bidang = Bidang::tryFrom($this->bidang) !== null ? $this->bidang : '';
        $this->limit = max(self::STEP, min(240, $this->limit));
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
