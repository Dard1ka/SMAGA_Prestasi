<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\EventWhen;
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
 * The box "Lewat jadwal, belum ditandai terbit" is where a publication waits after its reminder went out (D-122):
 * nothing is published by the scheduler, so it stays here until Humas presses "Tandai dipublikasikan".
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

        // D-122: the schedule is only a reminder. A row whose time arrived stays terjadwal ("lewat jadwal") until
        // Humas marks it as published; same rule as the sidebar count and the chip on the cards.
        $overdue = $scheduled->filter(fn (Publication $p): bool => $p->isOverdue($now));
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
            'events' => $canEvents ? $this->events($user) : [],
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
    private function events(User $user): array
    {
        return AppreciationEvent::query()
            ->open() // M1-E: an event Humas confirmed as held (even today) leaves the agenda
            ->whereDate('held_on', '>=', CarbonImmutable::today()->toDateString())
            ->with(array_map(fn (string $r): string => 'appreciations.achievement.'.$r, AchievementFacts::RELATIONS))
            // By day, then by start time (D-123); an event without a time sorts first within its day.
            ->orderBy('held_on')
            ->orderBy('held_time')
            ->oldest()
            ->limit(20)
            ->get()
            ->map(fn (AppreciationEvent $event): array => [
                'id' => (string) $event->getKey(),
                'name' => $event->name,
                'date' => EventWhen::long($event),
                'count' => $event->appreciations->count(),
                'items' => $event->appreciations
                    ->filter(fn (Appreciation $a): bool => $a->achievement !== null)
                    ->map(function (Appreciation $a) use ($user): array {
                        // Consent withdrawn after the row was added: no name/class without `student.view`.
                        $facts = AchievementFacts::of($a->achievement)->visibleTo($user);

                        return [
                            'id' => (string) $a->getKey(),
                            'student' => $facts->studentName.($facts->className !== null ? ' · '.$facts->className : ''),
                            'headline' => $facts->headline().' · '.$facts->competitionName,
                            'consentWithdrawn' => ! $facts->publishConsent,
                            'done' => $a->status === AppreciationStatus::Selesai,
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->all();
    }
}
