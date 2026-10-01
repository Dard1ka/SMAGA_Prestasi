<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\PublicationInput;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Publication;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Humas · Jadwal (UI-MAP §4d): read-only agenda of `terjadwal` publications grouped by date (overdue ones
 * first) and upcoming appreciation events with their achievements. Cards link back to the queue
 * (humas.beranda?pilih=<publication>).
 */
#[Title('Jadwal')]
#[Layout('layouts::app', ['subtitle' => 'Publikasi terjadwal dan acara apresiasi mendatang.'])]
class Jadwal extends Component
{
    public function mount(): void
    {
        $this->authorize('viewAny', Publication::class);
    }

    public function render(): View
    {
        /** @var User $user */
        $user = Auth::user();
        $now = CarbonImmutable::now();

        $scheduled = HumasQueries::visiblePublications()
            ->where('status', PublicationStatus::Terjadwal)
            ->with(HumasQueries::cardRelations())
            ->orderBy('scheduled_at')
            ->oldest()
            ->get();

        $overdue = $scheduled->filter(fn (Publication $p): bool => $p->scheduled_at === null || $p->scheduled_at->lessThan($now));
        $upcoming = $scheduled->diff($overdue);

        $days = $upcoming
            ->groupBy(fn (Publication $p): string => $p->scheduled_at->toDateString())
            ->map(fn ($items, string $day): array => [
                'key' => $day,
                'label' => HumasQueries::longDate(CarbonImmutable::parse($day)),
                'items' => $items->map(fn (Publication $p): array => $this->item($p))->values()->all(),
            ])
            ->values()
            ->all();

        $canEvents = $user->can('viewAny', AppreciationEvent::class);

        return view('livewire.humas.jadwal', [
            'overdue' => $overdue->map(fn (Publication $p): array => $this->item($p))->values()->all(),
            'days' => $days,
            'canEvents' => $canEvents,
            'events' => $canEvents ? $this->events() : [],
        ])->layoutData(['badges' => HumasQueries::badges($user)]);
    }

    /** @return array<string, string|null> */
    private function item(Publication $publication): array
    {
        $facts = AchievementFacts::of($publication->achievement);

        return [
            'id' => (string) $publication->getKey(),
            'time' => $publication->scheduled_at !== null
                ? $publication->scheduled_at->locale('id')->translatedFormat('j M, H.i')
                : 'Belum ada jam',
            'title' => $facts->competitionName,
            'student' => $facts->studentName.($facts->className !== null ? ' · '.$facts->className : ''),
            'headline' => $facts->headline(),
            'channels' => PublicationInput::channelLabels($publication->channels),
            'href' => route('humas.beranda', ['pilih' => $publication->getKey()]),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return AppreciationEvent::query()
            ->whereDate('held_on', '>=', CarbonImmutable::today()->toDateString())
            ->with(array_map(fn (string $r): string => 'appreciations.achievement.'.$r, AchievementFacts::RELATIONS))
            ->orderBy('held_on')
            ->limit(20)
            ->get()
            ->map(fn (AppreciationEvent $event): array => [
                'id' => (string) $event->getKey(),
                'name' => $event->name,
                'date' => HumasQueries::longDate($event->held_on),
                'count' => $event->appreciations->count(),
                'items' => $event->appreciations
                    ->filter(fn (Appreciation $a): bool => $a->achievement !== null)
                    ->map(function (Appreciation $a): array {
                        $facts = AchievementFacts::of($a->achievement);

                        return [
                            'id' => (string) $a->getKey(),
                            'student' => $facts->studentName.($facts->className !== null ? ' · '.$facts->className : ''),
                            'headline' => $facts->headline().' · '.$facts->competitionName,
                            'done' => $a->status === AppreciationStatus::Selesai,
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
