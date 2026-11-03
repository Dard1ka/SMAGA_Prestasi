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
 * student or competition name. A row whose student's account was later disabled or deleted (NamedStudent) stays
 * listed without the student name or class (nor a match on the name) unless the viewer has `student.view`
 * (SECURITY §3i). No flyer column: STH makes no flyer (M3-E); a file generated before that stays in
 * `publications.flyer_file_id`, unlinked from this screen. "Lihat" opens the publication in the panel of Antrean
 * publikasi, where the caption and the data for the flyer are; such a row has no link (the queue no longer holds it).
 * No consent chip since D-162 (20 Okt 2026).
 */
#[Title('Arsip publikasi')]
#[Layout('layouts::app', ['subtitle' => 'Prestasi yang sudah dipublikasikan.'])]
class Arsip extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    /** The search reads at most this many characters (the box keeps what was typed, the query trims). */
    public const SEARCH_MAX = 100;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Publication::class);
        $this->clampSearch();
    }

    /**
     * A new search starts on page 1. The typed text is not written back trimmed (SV1, backlog #81): Livewire would put
     * the server's value into the box while Humas is still typing and eat the space before the next word ("Nadia " +
     * pause + "Putri" became "NadiaPutri"). render() trims the term; only a text longer than the search reads is cut.
     */
    public function updatedQ(): void
    {
        $this->clampSearch();
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

        HumasQueries::search($query, mb_substr(trim($this->q), 0, self::SEARCH_MAX), ! $user->can(Permission::StudentView->value));

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
                // "Lihat" opens the panel of this publication (caption, kanal, data untuk flyer; QA Q2-19). Not for a
                // student who may not be named any more: the queue no longer holds that publication, for any viewer.
                'href' => $facts->named ? route('humas.beranda', ['pilih' => $p->getKey()]) : null,
            ];
        })->all();

        return view('livewire.humas.arsip', [
            'rows' => $rows,
            'page' => $page,
            'total' => $page->total(),
        ])->layoutData(['badges' => HumasQueries::badges($user), 'search' => false]);
    }

    /** Only a text longer than the search reads is cut; the box keeps everything else as typed, spaces included. */
    private function clampSearch(): void
    {
        if (mb_strlen($this->q) > self::SEARCH_MAX) {
            $this->q = mb_substr($this->q, 0, self::SEARCH_MAX);
        }
    }
}
