<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Enums\AppreciationStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Apresiasi (UI-MAP §4d; shared: humas.apresiasi.index and kesiswaan.apresiasi.index). Authorized by
 * `appreciation.manage` through AppreciationEventPolicy, never by role. Create an event, put verified
 * achievements (student with publish consent) on an upcoming event, mark a past event done. A row whose
 * student later withdrew consent stays with the chip "Izin dicabut"; its name and class are shown only to
 * a viewer with `student.view` (Kesiswaan, Admin), Humas sees "Siswa kelas XI" (SECURITY §3i, §3m-7).
 */
#[Title('Apresiasi')]
#[Layout('layouts::app', ['subtitle' => 'Acara apresiasi untuk prestasi siswa.'])]
class Apresiasi extends Component
{
    public const PAST_LIMIT = 10;

    public const OPTIONS_LIMIT = 100;

    public string $name = '';

    public string $heldOn = '';

    #[Locked]
    public ?string $addingEventId = null;

    public string $achievementId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', AppreciationEvent::class);
    }

    public function createEvent(CreateAppreciationEvent $action): void
    {
        $this->authorize('create', AppreciationEvent::class);

        try {
            $action->handle($this->actor(), $this->name, $this->heldOn);
        } catch (ValidationException $e) {
            $this->fail($e, ['name' => 'name', 'held_on' => 'heldOn']);

            return;
        }

        $this->reset('name', 'heldOn');
        $this->resetErrorBag();
        $this->dispatch('sth-toast', message: 'Acara apresiasi dibuat.', tone: 'success');
    }

    public function startAdding(string $eventId): void
    {
        $event = $this->findEvent($eventId);
        $this->authorize('update', $event);

        $this->addingEventId = (string) $event->getKey();
        $this->achievementId = '';
        $this->resetErrorBag();
    }

    public function cancelAdding(): void
    {
        $this->addingEventId = null;
        $this->achievementId = '';
        $this->resetErrorBag();
    }

    public function addAchievement(RecordAppreciation $action): void
    {
        if ($this->addingEventId === null) {
            return;
        }

        $event = $this->findEvent($this->addingEventId);
        $this->authorize('update', $event);

        $achievement = $this->achievementId !== ''
            ? HumasQueries::appreciableAchievements()->find($this->achievementId)
            : null;

        if (! $achievement instanceof Achievement) {
            $this->addError('achievementId', 'Pilih prestasi.');

            return;
        }

        try {
            $action->handle($this->actor(), $event, $achievement);
        } catch (ValidationException $e) {
            $this->fail($e, ['achievement' => 'achievementId', 'event' => 'achievementId']);

            return;
        }

        $this->cancelAdding();
        $this->dispatch('sth-toast', message: 'Prestasi masuk daftar apresiasi. Siswa sudah diberi tahu.', tone: 'success');
    }

    public function complete(string $eventId, CompleteAppreciationEvent $action): void
    {
        $event = $this->findEvent($eventId);

        try {
            $count = $action->handle($this->actor(), $event);
        } catch (ValidationException $e) {
            $this->fail($e, []);

            return;
        }

        $this->dispatch('sth-toast', message: $count.' apresiasi ditandai selesai.', tone: 'success');
    }

    public function render(): View
    {
        $user = $this->actor();
        $today = CarbonImmutable::today()->toDateString();
        $relations = array_map(fn (string $r): string => 'appreciations.achievement.'.$r, AchievementFacts::RELATIONS);

        $upcoming = AppreciationEvent::query()
            ->whereDate('held_on', '>=', $today)
            ->with($relations)
            ->orderBy('held_on')
            ->oldest()
            ->get();

        $past = AppreciationEvent::query()
            ->whereDate('held_on', '<', $today)
            ->with($relations)
            ->orderByDesc('held_on')
            ->limit(self::PAST_LIMIT)
            ->get();

        $canCreate = $user->can('create', AppreciationEvent::class);

        return view('livewire.humas.apresiasi', [
            'upcoming' => $upcoming->map(fn (AppreciationEvent $e): array => $this->event($e, $user))->all(),
            'past' => $past->map(fn (AppreciationEvent $e): array => $this->event($e, $user))->all(),
            'canCreate' => $canCreate,
            'minDate' => $today,
            'achievementOptions' => $this->addingEventId !== null ? $this->achievementOptions($this->addingEventId) : [],
        ])->layoutData(['badges' => HumasQueries::badges($user)]);
    }

    /** @return array<string, mixed> */
    private function event(AppreciationEvent $event, User $user): array
    {
        $today = CarbonImmutable::today();
        $isUpcoming = ! $event->held_on->lessThan($today);
        $rows = $event->appreciations->filter(fn (Appreciation $a): bool => $a->achievement !== null);
        $pending = $rows->filter(fn (Appreciation $a): bool => $a->status === AppreciationStatus::Dijadwalkan)->count();
        $canUpdate = $user->can('update', $event);

        $chip = match (true) {
            $isUpcoming => ['blue', 'Akan datang'],
            $pending > 0 => ['orange', 'Perlu ditandai selesai'],
            $rows->isNotEmpty() => ['green', 'Selesai'],
            default => ['gray', 'Sudah lewat'],
        };

        return [
            'id' => (string) $event->getKey(),
            'name' => $event->name,
            'date' => HumasQueries::longDate($event->held_on),
            'count' => $rows->count(),
            'chip' => $chip,
            'adding' => $this->addingEventId === (string) $event->getKey(),
            'canAdd' => $isUpcoming && $canUpdate,
            'canComplete' => $canUpdate && ! $event->held_on->greaterThan($today) && $pending > 0,
            'items' => $rows
                ->map(function (Appreciation $a) use ($user): array {
                    // Consent withdrawn after the row was added: no name/class without `student.view`.
                    $facts = AchievementFacts::of($a->achievement)->visibleTo($user);

                    return [
                        'id' => (string) $a->getKey(),
                        'student' => $facts->studentName,
                        'class' => $facts->className,
                        'headline' => $facts->headline(),
                        'competition' => $facts->competitionName,
                        'consentWithdrawn' => ! $facts->publishConsent,
                        'status' => $a->status === AppreciationStatus::Selesai ? ['green', 'Selesai'] : ['blue', 'Dijadwalkan'],
                    ];
                })
                ->sortBy(fn (array $row): string => mb_strtolower($row['student']))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, string> achievement id => "Nama (Kelas) · Juara I Nasional · Lomba" */
    private function achievementOptions(string $eventId): array
    {
        return HumasQueries::appreciableAchievements()
            ->whereDoesntHave('appreciations', fn (Builder $a) => $a->where('appreciation_event_id', $eventId))
            ->with(['student.user', 'student.classroom', 'competition'])
            ->latest('verified_at')
            ->limit(self::OPTIONS_LIMIT)
            ->get()
            ->mapWithKeys(function (Achievement $achievement): array {
                $name = (string) ($achievement->student?->user?->name ?? 'Siswa');
                $class = $achievement->student?->classroom?->name;
                $level = $achievement->level?->shortLabel();

                return [(string) $achievement->getKey() => $name.($class !== null ? ' ('.$class.')' : '')
                    .' · '.trim($achievement->result->label().' '.$level)
                    .' · '.(string) ($achievement->competition?->name ?? 'Lomba')];
            })
            ->all();
    }

    private function findEvent(string $id): AppreciationEvent
    {
        return AppreciationEvent::query()->findOrFail($id);
    }

    /** @param array<string, string> $fields action error key => component property */
    private function fail(ValidationException $e, array $fields): void
    {
        foreach ($e->errors() as $key => $messages) {
            $message = (string) ($messages[0] ?? 'Periksa isian.');

            if (isset($fields[$key])) {
                $this->addError($fields[$key], $message);
            } else {
                $this->dispatch('sth-toast', message: $message, tone: 'error');
            }
        }
    }

    private function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
