<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\PublicationInput;
use App\Enums\Permission;
use App\Enums\PublicationStatus;
use App\Models\Publication;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Humas · Arsip (UI-MAP §4d): every published publication, newest first, 20 per page, search on
 * student or competition name. Rows whose student later withdrew publish consent stay listed (Humas has
 * to take the post down) and carry the chip "Izin dicabut": no student name or class (nor a match on the
 * name) unless the viewer has `student.view` (SECURITY §3i, §3m-7). No flyer column: STH makes no flyer
 * (M3-E); a file generated before that stays in `publications.flyer_file_id`, unlinked from this screen.
 * "Lihat" opens the publication in the panel of Antrean publikasi, where the caption and the data for the
 * flyer are; a row with withdrawn consent has no such link.
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
            ->with(HumasQueries::cardRelations())
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        HumasQueries::search($query, $this->q, ! $user->can(Permission::StudentView->value));

        $page = $query->paginate(self::PER_PAGE);

        $rows = $page->getCollection()->map(function (Publication $p) use ($user): array {
            $facts = AchievementFacts::of($p->achievement)->visibleTo($user);

            return [
                'id' => (string) $p->getKey(),
                'competition' => $facts->competitionName,
                'student' => $facts->studentName,
                'class' => $facts->className,
                'headline' => $facts->headline(),
                'channels' => PublicationInput::channelLabels($p->channels) ?: '–',
                'published' => HumasQueries::shortDate($p->published_at, true),
                'consentWithdrawn' => ! $facts->publishConsent,
                // "Lihat" opens the panel of this publication (caption, kanal, data untuk flyer; QA Q2-19). Not after
                // a withdrawn consent: the queue no longer holds that publication (404), for any viewer.
                'href' => $facts->publishConsent ? route('humas.beranda', ['pilih' => $p->getKey()]) : null,
            ];
        })->all();

        return view('livewire.humas.arsip', [
            'rows' => $rows,
            'page' => $page,
            'total' => $page->total(),
        ])->layoutData(['badges' => HumasQueries::badges($user), 'search' => false]);
    }
}
