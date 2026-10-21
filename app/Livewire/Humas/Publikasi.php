<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\EventWhen;
use App\Actions\Publications\FlyerData;
use App\Actions\Publications\PublicationInput;
use App\Actions\Publications\PublishPublication;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\RecordAppreciations;
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
 * over the facts (FlyerData, M3-E). Only verified achievements whose student may be named are listed (NamedStudent:
 * not disabled, not deleted; no consent since D-162).
 * `?pilih=<publication>` preselects a card (a warning toast when it left the visible queue, QA10; 404 for an unknown id).
 *
 * D-122 (lead, 2026-10-10): the schedule is only a reminder for Humas. A `terjadwal` card whose time has passed
 * stays in the lane "Terjadwal" with the chip "Lewat jadwal" until Humas presses "Tandai dipublikasikan"; nothing
 * becomes Dipublikasikan by itself and the student is told only then. That button asks first in the dialog
 * PUBLISH_DIALOG (askPublish() checks caption + channels and opens it, publish() is the confirm step), because it
 * cannot be undone and notifies the student.
 *
 * D-160 (the lead, 2026-10-19): a team has one card per member. "Tandai dipublikasikan" also publishes the teammates'
 * cards that still wait (PublishPublication::teammates(); the dialog names them and says how many students are told),
 * and "Masukkan ke acara apresiasi" puts the free teammates on the same event (RecordAppreciation::withTeammates()),
 * so every member of the team hears about the post and the event, not only the one whose card was open.
 */
#[Title('Publikasi prestasi')]
#[Layout('layouts::app', ['subtitle' => 'Prestasi terverifikasi yang siap dibagikan.'])]
class Publikasi extends Component
{
    public const PUBLISHED_PREVIEW = 2;

    /** Cards per lane and step of "Tampilkan lebih banyak". */
    public const LANE_STEP = 20;

    public const LANE_MAX = 200;

    /** Dialog "Tandai sudah terbit?" (replaces the native confirm of the browser). */
    public const PUBLISH_DIALOG = 'tandai-terbit';

    /** Line under the dialog title: who hears about it (never claim a notice nobody gets, D-109). */
    public const NOTICE_TOLD = 'Siswa langsung diberi tahu.';

    public const NOTICE_NOBODY = 'Akun siswa tidak aktif, jadi tidak ada notifikasi.';

    #[Locked]
    public ?string $selectedId = null;

    /** The card the open dialog "Tandai sudah terbit?" asks about; set only by askPublish(). */
    #[Locked]
    public ?string $publishingId = null;

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
            $chosen = $this->findLinked($pilih);

            if ($chosen !== null) {
                $this->choose($chosen);

                return;
            }
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

    /**
     * "Tandai dipublikasikan" in the panel: checks caption and channels the way PublishPublication will, so the
     * dialog never opens over a field error, then opens the dialog. Nothing changes and nobody is told before publish().
     */
    public function askPublish(): void
    {
        // A question from before is void: only what this call opens may be confirmed.
        $this->publishingId = null;
        $publication = $this->selectedOrGone();

        if ($publication === null || $this->publishedElsewhere($publication)) {
            return;
        }

        $this->authorize('publish', $publication);

        $errors = [];

        foreach ([fn () => PublicationInput::caption($this->caption, required: false), fn () => PublicationInput::channels($this->channels)] as $check) {
            try {
                $check();
            } catch (ValidationException $e) {
                $errors += $e->errors();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->resetErrorBag();
        $this->publishingId = (string) $publication->getKey();
        $this->dispatch('sth-modal-open', id: self::PUBLISH_DIALOG);
    }

    /**
     * Confirm in the dialog. Only the card askPublish() asked about is published. Without that question (a forged
     * call, or the second click of a double click after the first one went through) nothing happens: a silent
     * no-op, not a 404, so no error page appears on top of a publication that just succeeded.
     */
    public function publish(PublishPublication $action): void
    {
        $this->authorize('viewAny', Publication::class);

        $asked = $this->publishingId !== null && $this->publishingId === $this->selectedId;
        $this->closePublishDialog();

        if (! $asked) {
            return;
        }

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
        // Read from what the Action published (D-160: the teammates' cards too); a notice is claimed only when someone
        // was told (an alumnus or a disabled account is not; D-109).
        $published = [$publication, ...$action->team];
        $told = count(array_filter($published, fn (Publication $p): bool => PublishPublication::notifiesStudent($p)));
        $message = count($published) === 1
            ? 'Publikasi ditandai sudah terbit.'.($told > 0 ? ' Siswa sudah diberi tahu.' : '')
            : 'Publikasi ditandai sudah terbit untuk '.count($published).' anggota tim.'.($told > 0 ? ' '.$told.' siswa sudah diberi tahu.' : '');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    public function recordAppreciation(RecordAppreciations $action): void
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

        // D-160: the free teammates of the same report go on the event in the same click (one guru notice for all).
        $achievements = RecordAppreciation::withTeammates($publication->achievement);

        try {
            $rows = $action->handle($this->actor(), $event, $achievements);
        } catch (ValidationException $e) {
            $this->fail($e);

            return;
        }

        $told = $rows->filter(fn (Appreciation $row): bool => RecordAppreciation::notifiesStudent($row->achievement))->count();
        $message = $rows->count() === 1
            ? 'Masuk daftar apresiasi.'.($told > 0 ? ' Siswa sudah diberi tahu.' : '')
            : $rows->count().' anggota tim masuk daftar apresiasi.'.($told > 0 ? ' '.$told.' siswa sudah diberi tahu.' : '');

        $this->eventId = '';
        $this->resetErrorBag('eventId');
        $this->dispatch('sth-toast', message: $message, tone: 'success');
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
            'publishing' => $this->publishing($selected),
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
            // D-122: the scheduled time passed and Humas has not marked it as published yet (chip "Lewat jadwal").
            'overdue' => $publication->isOverdue(),
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
                // By day, then by start time (D-123); an event without a time sorts first within its day.
                ->orderBy('held_on')
                ->orderBy('held_time')
                ->oldest()
                ->limit(20)
                ->get();

        // D-122: a scheduled time that passed is not a publication. The row stays terjadwal and says "Lewat jadwal".
        $overdue = $publication->isOverdue();

        $statusChip = match ($status) {
            PublicationStatus::Siap => ['orange', 'Siap publikasi'],
            PublicationStatus::Terjadwal => $overdue
                ? ['orange', trim('Lewat jadwal '.($publication->scheduled_at !== null ? self::dateTime($publication->scheduled_at) : ''))]
                : ['blue', 'Terjadwal '.self::dateTime($publication->scheduled_at)],
            PublicationStatus::Dipublikasikan => ['green', 'Terbit '.HumasQueries::shortDate($publication->published_at, true)],
        };

        return [
            'id' => (string) $publication->getKey(),
            'heading' => $facts->competitionName.' · '.$facts->studentName,
            'status' => $status->value,
            'statusChip' => $statusChip,
            'overdue' => $overdue,
            // Will "Tandai dipublikasikan" tell the student? The overdue alert and the hint under the date promise
            // the notice only then (an alumnus is not told; D-109), like the dialog (publishing()).
            'notifies' => PublishPublication::notifiesStudent($publication),
            'published' => $status === PublicationStatus::Dipublikasikan,
            'publishedChannels' => PublicationInput::channelLabels($publication->channels),
            'publishedCaption' => $publication->caption,
            'flyerRows' => $flyer->rows,
            'flyerNotes' => $flyer->notes,
            'scheduleLabel' => $status === PublicationStatus::Terjadwal ? 'Jadwalkan ulang' : 'Jadwalkan',
            'canSchedule' => $user->can('schedule', $publication),
            'canPublish' => $user->can('publish', $publication),
            'canAppreciate' => $user->can('create', Appreciation::class),
            // "12 Okt 2026, 07.00" (D-123): the panel says when the event is, in the line and in the choice.
            'appreciations' => $recorded->map(fn (Appreciation $a): array => [
                'event' => $a->event->name,
                'date' => EventWhen::short($a->event),
                'done' => $a->status === AppreciationStatus::Selesai,
            ])->all(),
            'eventOptions' => $events->mapWithKeys(fn (AppreciationEvent $e): array => [
                (string) $e->getKey() => $e->name.' · '.EventWhen::short($e),
            ])->all(),
            // D-160: the teammates "Masukkan" takes along (free, verified, may be named).
            'appreciationTeam' => $events->isEmpty() ? [] : RecordAppreciation::withTeammates($publication->achievement)
                ->reject(fn ($a): bool => (string) $a->getKey() === (string) $publication->achievement_id)
                ->map(fn ($a): string => AchievementFacts::of($a)->studentName)
                ->values()->all(),
        ];
    }

    private function choose(Publication $publication): void
    {
        $facts = AchievementFacts::of($publication->achievement);
        // An overdue row (D-122) gets the next free slot: its own time is past and "Jadwalkan ulang" would refuse it.
        $at = $publication->scheduled_at !== null && $publication->scheduled_at->isFuture()
            ? $publication->scheduled_at
            : self::defaultSchedule();

        $this->selectedId = (string) $publication->getKey();
        $this->publishingId = null; // a question about another card (or an older state of this one) is void
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
     * `?pilih=` from a link (PublicationDue mail, Jadwal, Arsip "Lihat"). QA10: a publication that left the queue since
     * the link was made (the student disabled or deleted) gets the QA9 warning toast and the normal
     * first card instead of a full-screen 404; the toast names nobody. An id that is no publication at all stays a 404.
     */
    private function findLinked(string $id): ?Publication
    {
        $publication = HumasQueries::visiblePublications()
            ->with(HumasQueries::cardRelations())
            ->find($id);

        if ($publication === null) {
            abort_unless(Publication::query()->whereKey($id)->exists(), 404);
            $this->dispatch('sth-toast', message: 'Prestasi ini sudah tidak ada di antrean publikasi. Antrean sudah diperbarui.', tone: 'warning');
        }

        return $publication;
    }

    /**
     * The selected publication, or null when it left the queue since the panel was drawn (QA9): Admin disabled the
     * student's account (the row is no longer visible), and find() answered a full-screen 404. `selectedId` is
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

            if ($this->publishingId !== null) {
                $this->closePublishDialog();
            }

            $this->dispatch('sth-toast', message: 'Prestasi ini sudah tidak ada di antrean publikasi. Antrean sudah diperbarui.', tone: 'warning');
        }

        return $publication;
    }

    /**
     * A second tab or a colleague published the selected card meanwhile (QA9): PublicationPolicy refused it with a
     * full-screen 403 before the Action could say "sudah dipublikasikan". Say so; the panel shows the published
     * state. (Since D-122 the scheduler never publishes: only "Tandai dipublikasikan" gets a row here.)
     */
    private function publishedElsewhere(Publication $publication): bool
    {
        if ($publication->status !== PublicationStatus::Dipublikasikan) {
            return false;
        }

        if ($this->publishingId !== null) {
            $this->closePublishDialog();
        }

        $this->choose($publication);
        $this->dispatch('sth-toast', message: 'Publikasi ini sudah dipublikasikan. Antrean sudah diperbarui.', tone: 'warning');

        return true;
    }

    /**
     * Texts of the dialog "Tandai sudah terbit?" for the card askPublish() asked about, or null while nothing is
     * asked. The channels are the ones on the form: what the dialog shows is what "Tandai dipublikasikan" saves.
     *
     * @return array{name: string, who: string, channels: string, notice: string, team: list<string>}|null
     */
    private function publishing(?Publication $selected): ?array
    {
        if ($selected === null || $this->publishingId === null || (string) $selected->getKey() !== $this->publishingId) {
            return null;
        }

        $facts = AchievementFacts::of($selected->achievement);
        // D-160: the teammates' cards that are published in the same step.
        $team = PublishPublication::teammates($this->actor(), $selected);
        $told = collect([$selected, ...$team->all()])->filter(fn (Publication $p): bool => PublishPublication::notifiesStudent($p))->count();

        return [
            'name' => $facts->competitionName,
            'who' => $facts->studentName.($facts->className !== null ? ' · '.$facts->className : '').' · '.$facts->headline(),
            'channels' => PublicationInput::channelLabels($this->channels),
            // Only when someone is told (an alumnus or a disabled account is not; D-109).
            'notice' => match (true) {
                $told === 0 => self::NOTICE_NOBODY,
                $team->isEmpty() => self::NOTICE_TOLD,
                default => $told.' siswa langsung diberi tahu.',
            },
            'team' => $team->map(fn (Publication $p): string => AchievementFacts::of($p->achievement)->studentName)->values()->all(),
        ];
    }

    private function closePublishDialog(): void
    {
        $this->publishingId = null;
        $this->dispatch('sth-modal-close', id: self::PUBLISH_DIALOG);
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
