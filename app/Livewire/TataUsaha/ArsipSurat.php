<?php

declare(strict_types=1);

namespace App\Livewire\TataUsaha;

use App\Enums\LetterType;
use App\Models\Letter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tata Usaha · Arsip surat (UI-MAP "Arsip surat"): every issued letter, read-only (letters are immutable),
 * newest first, search by number or lomba, filter by type. `letter.view`; downloads are audited.
 * Revoked versions stay listed with "Dicabut" (B3); TU opens tu.surat.show to correct a current letter.
 */
#[Title('Arsip surat')]
class ArsipSurat extends Component
{
    use WithPagination;

    /** The search reads at most this many characters (the box keeps what was typed, the query trims). */
    public const SEARCH_MAX = 100;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'jenis', except: '')]
    public string $jenis = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Letter::class);
        $this->clampSearch();
        $this->jenis = LetterType::tryFrom($this->jenis)?->value ?? '';
    }

    /**
     * A new search starts on page 1. The typed text is not written back trimmed (SV1, backlog #81): Livewire would put
     * the server's value into the box while TU is still typing and eat the space before the next word ("Olimpiade " +
     * pause + "Kimia" became "OlimpiadeKimia"). render() trims the term; only a text longer than the search reads is cut.
     */
    public function updatedQ(): void
    {
        $this->clampSearch();
        $this->resetPage();
    }

    public function updatedJenis(): void
    {
        $this->jenis = LetterType::tryFrom($this->jenis)?->value ?? '';
        $this->resetPage();
    }

    public function download(string $letterId): StreamedResponse
    {
        Gate::authorize('viewAny', Letter::class);

        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthorizationException;
        }

        return SuratTugas::streamLetter($user, Letter::query()->findOrFail($letterId));
    }

    public function render(): View
    {
        Gate::authorize('viewAny', Letter::class);

        $term = mb_substr(trim($this->q), 0, self::SEARCH_MAX);
        $type = LetterType::tryFrom($this->jenis);

        $letters = Letter::query()
            // "Untuk" names no student in the trash of Admin › Kelas (Student excludes trashed rows; the lead, 5 Nov 2026).
            ->with(['file', 'replacement:id,replaces_letter_id,full_number', 'registration' => fn ($q) => $q->withTrashed(), 'registration.competition', 'registration.mentor',
                'registration.members' => fn ($q) => $q->whereHas('student'), 'registration.members.student.user'])
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('full_number', 'like', '%'.self::escapeLike($term).'%')
                ->orWhereHas('registration.competition', fn (Builder $c) => $c->withTrashed()->where('name', 'like', '%'.self::escapeLike($term).'%'))))
            ->orderByDesc('issued_on')
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('livewire.tata-usaha.arsip-surat', [
            'letters' => $letters,
            'typeOptions' => ['' => 'Semua jenis'] + LetterType::options(),
            // B3 (#11): TU opens the registration's letter page to correct (cabut + versi baru).
            'canCorrect' => Gate::allows('viewQueue', Letter::class),
            // M1-A: "Cetak" (inline PDF in a new tab) lives in the TU route group.
            'canPrint' => Route::has('tu.surat.cetak'),
        ])->layoutData([
            'subtitle' => 'Surat yang sudah terbit. Nomor dan isi tidak bisa diubah.',
            // One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
            'search' => false,
        ]);
    }

    /** Only a text longer than the search reads is cut; the box keeps everything else as typed, spaces included. */
    private function clampSearch(): void
    {
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
