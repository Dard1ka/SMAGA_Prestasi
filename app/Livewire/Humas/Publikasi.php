<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\FlyerData;
use App\Actions\Publications\PublicationInput;
use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\SchedulePublication;
use App\Enums\AppreciationStatus;
use App\Enums\PublicationChannel;
use App\Enums\PublicationStatus;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\Publication;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Humas · Antrean publikasi (UI-MAP #22, board Humas-Publikasi): 3-lane board (siap / terjadwal /
 * dipublikasikan) + panel "Siapkan publikasi" ("Data untuk flyer" with "Salin data", caption, channels,
 * schedule, publish, apresiasi). Humas designs the flyer in its own tools: STH draws none, it only hands
 * over the facts (FlyerData, M3-E). Only verified achievements whose student gave publish consent are listed.
 * `?pilih=<publication>` preselects a card (404 when it is not in the visible queue).
 */
#[Title('Publikasi prestasi')]
#[Layout('layouts::app', ['subtitle' => 'Prestasi terverifikasi yang siap dibagikan.'])]
class Publikasi extends Component
{
    public const PUBLISHED_PREVIEW = 2;

    /** Cards per lane and step of "Tampilkan lebih banyak". */
    public const LANE_STEP = 20;

    public const LANE_MAX = 200;

    #[Locked]
    public ?string $selectedId = null;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public string $caption = '';

    /** @var list<string> */
    public array $channels = [];

    public string $date = '';

    public string $time = '';

    public string $eventId = '';

    public int $laneLimit = self::LANE_STEP;

    public function tampilkanLebih(): void
    {
        $this->authorize('viewAny', Publication::class);
        $this->laneLimit = min(self::LANE_MAX, $this->laneLimit + self::LANE_STEP);
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Publication::class);
        $this->q = mb_substr(trim($this->q), 0, 100);

        $pilih = request()->query('pilih');

        if (is_string($pilih) && $pilih !== '') {
            $this->choose($this->find($pilih));

            return;
        }

        $query = HumasQueries::visiblePublications()
            ->whereIn('status', [PublicationStatus::Siap, PublicationStatus::Terjadwal])
            ->with(HumasQueries::cardRelations())
            ->orderByRaw('case when status = ? then 0 else 1 end', [PublicationStatus::Siap->value])
            ->orderBy('scheduled_at')
            ->oldest();
        HumasQueries::search($query, $this->q);
        $first = $query->first();

        if ($first !== null) {
            $this->choose($first);
        }
    }

    public function select(string $id): void
    {
        $publication = $this->find($id);
        $this->authorize('view', $publication);
        $this->choose($publication);
    }

    public function clearSearch(): void
    {
        $this->q = '';
    }

    public function schedule(SchedulePublication $action): void
    {
        $publication = $this->selectedOrGone();

        if ($publication === null || $this->publishedElsewhere($publication)) {
            return;
        }

        // Date, time, caption and channels are checked together, so nothing waits for a second try (QA5 #65).
        $errors = Validator::make(['date' => $this->date, 'time' => $this->time], [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ], [
            'date.required' => 'Pilih tanggal publikasi.',
            'date.date_format' => 'Pilih tanggal publikasi.',
            'time.required' => 'Pilih jam publikasi.',
            'time.date_format' => 'Pilih jam publikasi.',
        ])->errors()->toArray();

        foreach ([fn () => PublicationInput::caption($this->caption, required: true), fn () => PublicationInput::channels($this->channels)] as $check) {
            try {
                $check();
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        // Like Livewire's validate(): a passing check drops the messages of the previous try.
        $this->resetErrorBag();

        $at = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->date.' '.$this->time, (string) config('app.timezone'));

        try {
            $action->handle($this->actor(), $publication, $this->caption, $this->channels, $at);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        $this->resetErrorBag();
        $this->dispatch('sth-toast', message: 'Publikasi dijadwalkan.', tone: 'success');
    }

    public function publish(PublishPublication $action): void
    {
        $publication = $this->selectedOrGone();

        if ($publication === null || $this->publishedElsewhere($publication)) {
            return;
        }

        try {
            $action->handle($this->actor(), $publication, $this->caption, $this->channels);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        $this->resetErrorBag();
        // Only when someone was told (an alumnus or a disabled account is not; D-109).
        $this->dispatch('sth-toast', message: 'Publikasi ditandai sudah terbit.'.(PublishPublication::notifiesStudent($publication) ? ' Siswa sudah diberi tahu.' : ''), tone: 'success');
    }

    public function recordAppreciation(RecordAppreciation $action): void
    {
        $publication = $this->selectedOrGone();

        if ($publication === null) {
            return;
        }

        $publication->loadMissing('achievement');

        $event = $this->eventId !== ''
            ? AppreciationEvent::query()->open()->whereKey($this->eventId)->whereDate('held_on', '>=', CarbonImmutable::today()->toDateString())->first()
            : null;

        if ($event === null) {
            $this->addError('eventId', 'Pilih acara apresiasi.');

            return;
        }

        try {
            $action->handle($this->actor(), $event, $publication->achievement);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        $this->eventId = '';
        $this->resetErrorBag('eventId');
        $this->dispatch('sth-toast', message: 'Masuk daftar apresiasi.'.(RecordAppreciation::notifiesStudent($publication->achievement) ? ' Siswa sudah diberi tahu.' : ''), tone: 'success');
    }

    public function render(): View
    {
        $user = $this->actor();

        // A lane renders at most $laneLimit cards (a long backlog made the page several hundred kB); the chip shows the total.
        $this->laneLimit = max(self::LANE_STEP, min(self::LANE_MAX, $this->laneLimit));
        $siapCount = $this->lane(PublicationStatus::Siap)->count();
        $terjadwalCount = $this->lane(PublicationStatus::Terjadwal)->count();
        $siap = $this->lane(PublicationStatus::Siap)->oldest()->limit($this->laneLimit)->get();
        $terjadwal = $this->lane(PublicationStatus::Terjadwal)->orderBy('scheduled_at')->oldest()->limit($this->laneLimit)->get();
        $publishedCount = $this->lane(PublicationStatus::Dipublikasikan)->count();
        $published = $this->lane(PublicationStatus::Dipublikasikan)
            ->latest('published_at')
            ->limit(self::PUBLISHED_PREVIEW)
            ->get();

        $selected = $this->selectedId !== null
            ? HumasQueries::visiblePublications()
                ->with([...HumasQueries::cardRelations(), 'achievement.appreciations.event'])
                ->find($this->selectedId)
            : null;

        if ($selected === null) {
            $this->selectedId = null;
        }

        return view('livewire.humas.publikasi', [
            'lanes' => [
                ['key' => 'siap', 'title' => 'Siap publikasi', 'dot' => 'bg-orange', 'tone' => 'orange', 'count' => $siapCount,
                    'more' => $this->laneLimit < self::LANE_MAX ? max(0, $siapCount - $siap->count()) : 0,
                    'cards' => $siap->map(fn (Publication $p): array => $this->card($p))->all(), 'empty' => $this->q !== '' ? 'Tidak ada yang cocok.' : 'Belum ada prestasi baru.'],
                ['key' => 'terjadwal', 'title' => 'Terjadwal', 'dot' => 'bg-primary', 'tone' => 'blue', 'count' => $terjadwalCount,
                    'more' => $this->laneLimit < self::LANE_MAX ? max(0, $terjadwalCount - $terjadwal->count()) : 0,
                    'cards' => $terjadwal->map(fn (Publication $p): array => $this->card($p))->all(), 'empty' => $this->q !== '' ? 'Tidak ada yang cocok.' : 'Belum ada yang dijadwalkan.'],
            ],
            'published' => $published->map(fn (Publication $p): array => $this->card($p))->all(),
            'publishedCount' => $publishedCount,
            'publishedMore' => max(0, $publishedCount - self::PUBLISHED_PREVIEW),
            'boardEmpty' => $siap->isEmpty() && $terjadwal->isEmpty() && $publishedCount === 0,
            'panel' => $selected !== null ? $this->panel($selected, $user) : null,
            'channelOptions' => PublicationChannel::options(),
        ])->layoutData([
            'badges' => $this->freshBadges($user),
            'search' => ['action' => route('humas.beranda'), 'placeholder' => 'Cari prestasi atau siswa'],
        ]);
    }

    /**
     * Sidebar counts for the layout, also sent to the browser: the sidebar is rendered once per page load, so
     * without the `sth-badges` event (app.js) the number stayed until the next reload.
     *
     * @return array<string, int>
     */
    private function freshBadges(?User $user): array
    {
        $badges = HumasQueries::badges($user);
        $this->dispatch('sth-badges', badges: $badges);

        return $badges;
    }

    /** @return Builder<Publication> */
    private function lane(PublicationStatus $status): Builder
    {
        $query = HumasQueries::visiblePublications()
            ->where('status', $status)
            ->with(HumasQueries::cardRelations());

        HumasQueries::search($query, $this->q);

        return $query;
    }

    /** @return array<string, mixed> */
    private function card(Publication $publication): array
    {
        $facts = AchievementFacts::of($publication->achievement);
        $cssKey = $facts->bidang?->cssKey();

        return [
            'id' => (string) $publication->getKey(),
            'title' => $facts->competitionName,
            'headline' => $facts->headline(),
            'result' => $facts->resultLabel,
            'student' => $facts->studentName,
            'class' => $facts->className,
            'mentor' => $facts->mentorShort !== null ? 'Pembimbing '.$facts->mentorShort : 'Tanpa guru pembimbing',
            'when' => $publication->status === PublicationStatus::Terjadwal
                ? trim(HumasQueries::shortDate($publication->scheduled_at).' · '.PublicationInput::channelLabels($publication->channels), ' ·')
                : null,
            'publishedOn' => $publication->published_at !== null ? 'Terbit '.HumasQueries::shortDate($publication->published_at) : null,
            'photo' => $facts->photoConsent,
            // Cover band: the template drawing of the bidang, picked from the lomba id (<x-sth.sampul>).
            'bidang' => $cssKey,
            'seed' => (string) $publication->achievement?->competition_id,
            'border' => HumasQueries::bidangBorder($cssKey),
            'selected' => (string) $publication->getKey() === $this->selectedId,
        ];
    }

    /** @return array<string, mixed> */
    private function panel(Publication $publication, User $user): array
    {
        $facts = AchievementFacts::of($publication->achievement);
        $flyer = FlyerData::of($publication->achievement);
        $status = $publication->status;
        $recorded = $publication->achievement->appreciations
            ->filter(fn (Appreciation $a): bool => $a->event !== null)
            ->sortBy(fn (Appreciation $a): string => $a->event->held_on->toDateString())
            ->values();
        // M2-B (Q2-10): one achievement sits on one event. Once it is scheduled or was appreciated,
        // RecordAppreciation refuses a second event, so no "Masukkan" is offered (the panel lists where it is).
        $events = $publication->achievement->appreciations->isNotEmpty()
            ? collect()
            : AppreciationEvent::query()
                ->open() // M1-E: a confirmed event takes no more prestasi
                ->whereDate('held_on', '>=', CarbonImmutable::today()->toDateString())
                ->orderBy('held_on')
                ->limit(20)
                ->get();

        $statusChip = match ($status) {
            PublicationStatus::Siap => ['orange', 'Siap publikasi'],
            PublicationStatus::Terjadwal => ['blue', 'Terjadwal '.self::dateTime($publication->scheduled_at)],
            PublicationStatus::Dipublikasikan => ['green', 'Terbit '.HumasQueries::shortDate($publication->published_at, true)],
        };

        return [
            'id' => (string) $publication->getKey(),
            'heading' => $facts->competitionName.' · '.$facts->studentName,
            'status' => $status->value,
            'statusChip' => $statusChip,
            'published' => $status === PublicationStatus::Dipublikasikan,
            'publishedChannels' => PublicationInput::channelLabels($publication->channels),
            'publishedCaption' => $publication->caption,
            'flyerRows' => $flyer->rows,
            'flyerNotes' => $flyer->notes,
            'scheduleLabel' => $status === PublicationStatus::Terjadwal ? 'Jadwalkan ulang' : 'Jadwalkan',
            'canSchedule' => $user->can('schedule', $publication),
            'canPublish' => $user->can('publish', $publication),
            'canAppreciate' => $user->can('create', Appreciation::class),
            'appreciations' => $recorded->map(fn (Appreciation $a): array => [
                'event' => $a->event->name,
                'date' => HumasQueries::shortDate($a->event->held_on, true),
                'done' => $a->status === AppreciationStatus::Selesai,
            ])->all(),
            'eventOptions' => $events->mapWithKeys(fn (AppreciationEvent $e): array => [
                (string) $e->getKey() => $e->name.' · '.HumasQueries::shortDate($e->held_on, true),
            ])->all(),
        ];
    }

    private function choose(Publication $publication): void
    {
        $facts = AchievementFacts::of($publication->achievement);
        $at = $publication->scheduled_at ?? self::defaultSchedule();

        $this->selectedId = (string) $publication->getKey();
        $this->caption = (string) ($publication->caption ?? $facts->suggestedCaption());
        $this->channels = array_values(array_filter(
            (array) $publication->channels,
            fn (mixed $c): bool => is_string($c) && PublicationChannel::tryFrom($c) !== null,
        )) ?: [PublicationChannel::Instagram->value, PublicationChannel::Website->value];
        $this->date = $at->format('Y-m-d');
        $this->time = $at->format('H:i');
        $this->eventId = '';
        $this->resetErrorBag();
    }

    /** The next 09.00 (today when it is still before 09.00). */
    private static function defaultSchedule(): CarbonImmutable
    {
        $nine = CarbonImmutable::today()->setTime(9, 0);

        return $nine->isFuture() ? $nine : $nine->addDay();
    }

    private static function dateTime(?DateTimeInterface $at): string
    {
        return $at !== null
            ? CarbonImmutable::instance($at)->locale('id')->translatedFormat('j M, H.i')
            : '–';
    }

    /** Visible publication or 404 (not in the queue = not yours). */
    private function find(string $id): Publication
    {
        return HumasQueries::visiblePublications()
            ->with(HumasQueries::cardRelations())
            ->findOrFail($id);
    }

    /**
     * The selected publication, or null when it left the queue since the panel was drawn (QA9): Admin withdrew the
     * student's publish consent (the row is no longer visible), and find() answered a full-screen 404. `selectedId` is
     * #[Locked] and set only from the visible queue, so this says nothing about a row Humas never saw; the panel closes.
     */
    private function selectedOrGone(): ?Publication
    {
        $this->authorize('viewAny', Publication::class);
        abort_if($this->selectedId === null, 404);

        $publication = HumasQueries::visiblePublications()
            ->with(HumasQueries::cardRelations())
            ->find($this->selectedId);

        if ($publication === null) {
            $this->selectedId = null;
            $this->resetErrorBag();
            $this->dispatch('sth-toast', message: 'Prestasi ini sudah tidak ada di antrean publikasi. Antrean sudah diperbarui.', tone: 'warning');
        }

        return $publication;
    }

    /**
     * The auto-publish job (every 5 minutes) or a second tab published the selected card meanwhile (QA9):
     * PublicationPolicy refused it with a full-screen 403 before the Action could say "sudah dipublikasikan". Say so;
     * the panel shows the published state.
     */
    private function publishedElsewhere(Publication $publication): bool
    {
        if ($publication->status !== PublicationStatus::Dipublikasikan) {
            return false;
        }

        $this->choose($publication);
        $this->dispatch('sth-toast', message: 'Publikasi ini sudah dipublikasikan. Antrean sudah diperbarui.', tone: 'warning');

        return true;
    }

    /** Field errors next to the field, anything else as an error toast. */
    private function fail(ValidationException $e): void
    {
        $fields = [
            'caption' => 'caption',
            'channels' => 'channels',
            'scheduled_at' => 'date',
            'event' => 'eventId',
            'achievement' => 'eventId',
        ];

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
