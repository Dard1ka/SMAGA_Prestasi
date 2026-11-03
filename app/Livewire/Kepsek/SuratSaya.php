<?php

declare(strict_types=1);

namespace App\Livewire\Kepsek;

use App\Enums\LetterType;
use App\Livewire\TataUsaha\SuratTugas;
use App\Models\Letter;
use App\Models\PrincipalReplacement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Kepala Sekolah · Surat atas nama saya (SECURITY §3m-5 "Kepala Sekolah melihat daftar semua surat atas namanya;
 * alert bila > 20 surat/hari", backlog #6, owner B5; route `kepsek.surat.index`).
 *
 * Letters are signed with the current Kepala Sekolah's name and signature, so "in my name" = every letter
 * generated since this account became Kepala Sekolah (the last applied PrincipalReplacement to it; without one,
 * every letter). Revoked versions stay listed with "Dicabut" (B3 correction flow). Read-only; downloads go
 * through SuratTugas::streamLetter (LetterPolicy::view + audit `letter.downloaded`). `letter.view`.
 */
#[Title('Surat atas nama saya')]
// One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
#[Layout('layouts::app', ['subtitle' => 'Semua surat tugas yang terbit dengan nama dan tanda tangan Kepala Sekolah.', 'search' => false])]
final class SuratSaya extends Component
{
    use WithPagination;

    /** SECURITY §3m-5: more letters than this on one day deserve a look. */
    public const DAILY_ALERT = 20;

    /** The search reads at most this many characters (the box keeps what was typed, the query trims). */
    public const SEARCH_MAX = 100;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'jenis', except: '')]
    public string $jenis = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Letter::class);
        $this->normalize();
    }

    public function updated(): void
    {
        $this->normalize();
        $this->resetPage();
    }

    public function download(string $letterId): StreamedResponse
    {
        Gate::authorize('viewAny', Letter::class);

        /** @var Letter $letter */
        $letter = $this->baseQuery()->whereKey($letterId)->firstOrFail();

        return SuratTugas::streamLetter($this->actor(), $letter);
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Letter::class);

        $since = $this->since();
        $today = CarbonImmutable::now(config('app.timezone'))->startOfDay();
        $term = mb_substr(trim($this->q), 0, self::SEARCH_MAX);
        $type = LetterType::tryFrom($this->jenis);

        $letters = $this->baseQuery()
            ->with([
                'generator:id,name',
                'revoker:id,name',
                'replacement:id,replaces_letter_id,full_number,file_id,generated_at',
                'registration' => fn ($q) => $q->withTrashed()->select(['id', 'competition_id', 'mentor_id']),
                'registration.competition' => fn ($q) => $q->withTrashed()->select(['id', 'name']),
            ])
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when($this->status === 'terkirim', fn (Builder $q) => $q->whereNull('revoked_at')->whereNotNull('sent_at'))
            ->when($this->status === 'belum', fn (Builder $q) => $q->whereNull('revoked_at')->whereNull('sent_at'))
            ->when($this->status === 'dicabut', fn (Builder $q) => $q->whereNotNull('revoked_at'))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('full_number', 'like', '%'.addcslashes($term, '%_\\').'%')
                ->orWhereHas('registration.competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', '%'.addcslashes($term, '%_\\').'%'))))
            ->orderByDesc('generated_at')
            ->orderByDesc('created_at')
            ->paginate(20);

        $perDay = $this->baseQuery()
            ->where('generated_at', '>=', $today->subDays(29))
            ->get(['id', 'generated_at'])
            ->countBy(fn (Letter $l) => $l->generated_at?->timezone(config('app.timezone'))->toDateString());
        $busiest = $perDay->sortDesc()->keys()->first();

        return view('livewire.kepsek.surat-saya', [
            'letters' => $letters,
            'since' => $since,
            'stats' => [
                'total' => $this->baseQuery()->count(),
                'today' => (int) ($perDay[$today->toDateString()] ?? 0),
                'month' => $this->baseQuery()->where('generated_at', '>=', $today->startOfMonth())->count(),
                'revoked' => $this->baseQuery()->whereNotNull('revoked_at')->count(),
                'busiestDay' => $busiest,
                'busiestCount' => $busiest !== null ? (int) $perDay[$busiest] : 0,
            ],
            'alertDays' => $perDay->filter(fn (int $n) => $n > self::DAILY_ALERT)->keys()->sortDesc()->values()->all(),
            'typeOptions' => ['' => 'Semua jenis'] + LetterType::options(),
            'statusOptions' => ['' => 'Semua status', 'terkirim' => 'Terkirim', 'belum' => 'Belum dikirim', 'dicabut' => 'Dicabut'],
        ]);
    }

    /** @return Builder<Letter> letters generated while this account holds the Kepala Sekolah role */
    private function baseQuery(): Builder
    {
        $since = $this->since();

        return Letter::query()->when($since !== null, fn (Builder $q) => $q->where('generated_at', '>=', $since));
    }

    private function since(): ?CarbonImmutable
    {
        /** @var PrincipalReplacement|null $replacement */
        $replacement = PrincipalReplacement::query()
            ->whereNotNull('applied_at')
            ->where('to_user_id', $this->actor()->getKey())
            ->latest('applied_at')
            ->first(['id', 'applied_at']);

        return $replacement?->applied_at;
    }

    private function normalize(): void
    {
        // The search box keeps the text as typed (SV1, backlog #81): a trimmed value written back here would land in the
        // box while the Kepala Sekolah is still typing and eat the space before the next word ("Olimpiade " + pause +
        // "Kimia" became "OlimpiadeKimia"). render() trims the term; here it is only cut to what the search reads.
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }

        $this->jenis = LetterType::tryFrom($this->jenis)?->value ?? '';
        $this->status = in_array($this->status, ['terkirim', 'belum', 'dicabut'], true) ? $this->status : '';
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }
}
