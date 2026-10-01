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

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'jenis', except: '')]
    public string $jenis = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Letter::class);
        $this->q = mb_substr(trim($this->q), 0, 100);
        $this->jenis = LetterType::tryFrom($this->jenis)?->value ?? '';
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr(trim($this->q), 0, 100);
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

        $term = trim($this->q);
        $type = LetterType::tryFrom($this->jenis);

        $letters = Letter::query()
            ->with(['file', 'replacement:id,replaces_letter_id,full_number', 'registration' => fn ($q) => $q->withTrashed(), 'registration.competition', 'registration.mentor', 'registration.members.student.user'])
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
        ])->layoutData([
            'subtitle' => 'Surat yang sudah terbit. Nomor dan isi tidak bisa diubah.',
        ]);
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
