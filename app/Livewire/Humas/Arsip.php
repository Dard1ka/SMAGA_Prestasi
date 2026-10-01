<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\PublicationInput;
use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Humas · Arsip (UI-MAP §4d): every published publication, newest first, 20 per page, search on
 * student or competition name. Rows whose student later withdrew publish consent stay listed (Humas has
 * to take the post down) and carry the chip "Izin dicabut".
 */
#[Title('Arsip publikasi')]
#[Layout('layouts::app', ['subtitle' => 'Prestasi yang sudah dipublikasikan.'])]
class Arsip extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Publication::class);
        $this->q = mb_substr(trim($this->q), 0, 100);
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr(trim($this->q), 0, 100);
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->resetPage();
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();

        $query = Publication::query()
            ->where('status', PublicationStatus::Dipublikasikan)
            ->with([...HumasQueries::cardRelations(), 'flyerFile'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        HumasQueries::search($query, $this->q);

        $page = $query->paginate(self::PER_PAGE);
        $canDownload = Route::has('files.show');

        $rows = $page->getCollection()->map(function (Publication $p) use ($canDownload): array {
            $facts = AchievementFacts::of($p->achievement);

            return [
                'id' => (string) $p->getKey(),
                'competition' => $facts->competitionName,
                'student' => $facts->studentName,
                'class' => $facts->className,
                'headline' => $facts->headline(),
                'channels' => PublicationInput::channelLabels($p->channels) ?: '–',
                'published' => HumasQueries::shortDate($p->published_at, true),
                'consentWithdrawn' => ! $facts->publishConsent,
                'flyerUrl' => $canDownload && $p->flyerFile !== null ? route('files.show', $p->flyerFile) : null,
            ];
        })->all();

        return view('livewire.humas.arsip', [
            'rows' => $rows,
            'page' => $page,
            'total' => $page->total(),
        ])->layoutData(['badges' => HumasQueries::badges($user), 'search' => false]);
    }
}
