<?php

declare(strict_types=1);

namespace App\Livewire\Humas;

use App\Actions\Publications\AchievementFacts;
use App\Actions\Publications\CompleteAppreciationEvent;
use App\Actions\Publications\CreateAppreciationEvent;
use App\Actions\Publications\DeleteAppreciationEvent;
use App\Actions\Publications\RecordAppreciation;
use App\Actions\Publications\ReleaseAppreciation;
use App\Enums\AppreciationStatus;
use App\Enums\UserStatus;
use App\Models\Achievement;
use App\Models\Appreciation;
use App\Models\AppreciationEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Activitylog\Models\Activity;

/**
 * Apresiasi (UI-MAP §4d; shared: humas.apresiasi.index and kesiswaan.apresiasi.index). Authorized by
 * `appreciation.manage` through AppreciationEventPolicy, never by role. Create an event, put verified
 * achievements (student with publish consent) on an upcoming event, confirm that an event was held
 * ("Tandai sudah dilaksanakan") or delete one that was created by mistake or cancelled ("Hapus acara");
 * both sit behind the confirmation dialog `apresiasi-konfirmasi` (M1-E, lead 2026-10-04). A confirmed event
 * moves to "Sudah dilaksanakan" and can no longer be changed or deleted. A row whose student later withdrew
 * consent stays with the chip "Izin dicabut"; its name and class are shown only to a viewer with
 * `student.view` (Kesiswaan, Admin), Humas sees "Siswa kelas XI" (SECURITY §3i, §3m-7).
 *
 * M2-B (QA 2026-10-05, Q2-10): one achievement sits on one open event only, so "Tambah prestasi" offers only
 * achievements that are on no event, and every scheduled row of an open event has "Lepas dari acara"
 * (confirmRelease → the same dialog → ReleaseAppreciation).
 */
#[Title('Apresiasi')]
#[Layout('layouts::app', ['subtitle' => 'Acara apresiasi untuk prestasi siswa.'])]
class Apresiasi extends Component
{
    /** Unconfirmed events whose date has passed: an action list, not an archive. */
    public const OVERDUE_LIMIT = 50;

    /** "Sudah dilaksanakan" comes in steps (D-72). */
    public const DONE_STEP = 10;

    public const DONE_MAX = 200;

    public const OPTIONS_LIMIT = 100;

    public const CONFIRM_DIALOG = 'apresiasi-konfirmasi';

    public const CONFIRM_COMPLETE = 'selesai';

    public const CONFIRM_DELETE = 'hapus';

    /** "Lepas dari acara": one scheduled row leaves an event that is not confirmed (M2-B, Q2-10). */
    public const CONFIRM_RELEASE = 'lepas';

    public string $name = '';

    public string $heldOn = '';

    #[Locked]
    public ?string $addingEventId = null;

    public string $achievementId = '';

    /** Event of the open confirmation dialog; set only by confirmComplete() / confirmDelete() / confirmRelease(). */
    #[Locked]
    public ?string $confirmEventId = null;

    #[Locked]
    public string $confirmAction = '';

    /** Row of the open "Lepas dari acara" dialog; set only by confirmRelease(). */
    #[Locked]
    public ?string $confirmRowId = null;

    #[Locked]
    public int $doneLimit = self::DONE_STEP;

    public function mount(): void
    {
        $this->authorize('viewAny', AppreciationEvent::class);
    }

    public function createEvent(CreateAppreciationEvent $action): void
    {
        $this->authorize('create', AppreciationEvent::class);
        // Only this try's messages: a name filled in since the last try loses its old error.
        $this->resetErrorBag(['name', 'heldOn']);

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

        if ($event === null) {
            return;
        }

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

        if ($event === null) {
            return;
        }

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
        $this->dispatch('sth-toast', message: 'Prestasi masuk daftar apresiasi.'.(RecordAppreciation::notifiesStudent($achievement) ? ' Siswa sudah diberi tahu.' : ''), tone: 'success');
    }

    /**
     * Opens the dialog "Tandai sudah dilaksanakan?". Nothing changes until runConfirmed(). An event that
     * cannot be confirmed (stale list: date not reached, no scheduled row, already confirmed) answers with
     * the Action's reason instead of a dialog that could only fail.
     */
    public function confirmComplete(string $eventId): void
    {
        $event = $this->findEvent($eventId);

        if ($event === null) {
            return;
        }

        $this->authorize('update', $event);

        $scheduled = $event->appreciations()->where('status', AppreciationStatus::Dijadwalkan)->count();
        $blocker = CompleteAppreciationEvent::blocker($event, $scheduled);

        if ($blocker !== null) {
            $this->dispatch('sth-toast', message: CompleteAppreciationEvent::MESSAGES[$blocker], tone: 'error');

            return;
        }

        $this->openConfirm($event, self::CONFIRM_COMPLETE);
    }

    /** Opens the dialog "Hapus acara?". A confirmed event answers with the reason instead (stale list). */
    public function confirmDelete(string $eventId): void
    {
        $event = $this->findEvent($eventId);

        if ($event === null) {
            return;
        }

        $this->authorize('update', $event);

        if (! $this->actor()->can('delete', $event)) {
            $this->dispatch('sth-toast', message: DeleteAppreciationEvent::COMPLETED_MESSAGE, tone: 'error');

            return;
        }

        $this->openConfirm($event, self::CONFIRM_DELETE);
    }

    /**
     * Opens the dialog "Lepas dari acara?" for one row. A row that can no longer be released (stale list: the
     * event was confirmed, or a colleague released the row) answers with the reason instead.
     */
    public function confirmRelease(string $appreciationId): void
    {
        $row = Appreciation::query()->with('event')->find($appreciationId);

        if ($row === null || $row->event === null) {
            $this->authorize('viewAny', AppreciationEvent::class);
            $this->dispatch('sth-toast', message: ReleaseAppreciation::GONE_MESSAGE, tone: 'error');

            return;
        }

        $this->authorize('update', $row->event);

        if (! $this->actor()->can('delete', $row)) {
            $this->dispatch('sth-toast', message: ReleaseAppreciation::COMPLETED_MESSAGE, tone: 'error');

            return;
        }

        $this->confirmRowId = (string) $row->getKey();
        $this->openConfirm($row->event, self::CONFIRM_RELEASE);
    }

    public function runConfirmed(CompleteAppreciationEvent $complete, DeleteAppreciationEvent $delete, ReleaseAppreciation $release): void
    {
        abort_if($this->confirmEventId === null, 404);

        $event = $this->findEvent($this->confirmEventId);

        if ($event === null) {
            return;
        }

        $this->authorize('update', $event);

        try {
            if ($this->confirmAction === self::CONFIRM_COMPLETE) {
                $count = $complete->handle($this->actor(), $event);
                $message = 'Acara ditandai sudah dilaksanakan. '.$count.' prestasi sudah diapresiasi.';
            } elseif ($this->confirmAction === self::CONFIRM_DELETE) {
                $told = DeleteAppreciationEvent::studentsToTell($event);
                $count = $delete->handle($this->actor(), $event);
                $message = match (true) {
                    $count > 0 && $told > 0 => 'Acara dihapus. '.$count.' prestasi dilepas dari acara ini dan siswanya diberi tahu.',
                    $count > 0 => 'Acara dihapus. '.$count.' prestasi dilepas dari acara ini.',
                    default => 'Acara dihapus.',
                };
            } elseif ($this->confirmAction === self::CONFIRM_RELEASE) {
                $row = $this->confirmRowId !== null ? Appreciation::query()->find($this->confirmRowId) : null;

                if ($row === null) {
                    throw ValidationException::withMessages(['appreciation' => ReleaseAppreciation::GONE_MESSAGE]);
                }

                $told = $release->handle($this->actor(), $row);
                $message = 'Prestasi dilepas dari acara ini.'.($told ? ' Siswanya diberi tahu.' : '');
            } else {
                abort(404);
            }
        } catch (ValidationException $e) {
            $this->closeConfirm();
            $this->fail($e, []);

            return;
        }

        // The event is gone or locked: its "Tambah prestasi" form goes too. A release keeps the event as it is.
        if ($this->confirmAction !== self::CONFIRM_RELEASE && $this->addingEventId === $this->confirmEventId) {
            $this->cancelAdding();
        }

        $this->closeConfirm();
        $this->dispatch('sth-toast', message: $message, tone: 'success');
    }

    public function showMoreDone(): void
    {
        $this->doneLimit += self::DONE_STEP;
    }

    public function render(): View
    {
        $user = $this->actor();
        $today = CarbonImmutable::today();
        $relations = array_map(fn (string $r): string => 'appreciations.achievement.'.$r, AchievementFacts::RELATIONS);
        $this->doneLimit = max(self::DONE_STEP, min(self::DONE_MAX, $this->doneLimit));

        $upcoming = AppreciationEvent::query()
            ->open()
            ->whereDate('held_on', '>=', $today->toDateString())
            ->with($relations)
            ->orderBy('held_on')
            ->oldest()
            ->get();

        $overdue = AppreciationEvent::query()
            ->open()
            ->whereDate('held_on', '<', $today->toDateString())
            ->with($relations)
            ->orderBy('held_on')
            ->oldest()
            ->limit(self::OVERDUE_LIMIT)
            ->get();

        $doneCount = AppreciationEvent::query()->completed()->count();
        $done = AppreciationEvent::query()
            ->completed()
            ->with([...$relations, 'completedBy:id,name'])
            ->orderByDesc('held_on')
            ->orderByDesc('completed_at')
            ->limit($this->doneLimit)
            ->get();

        $map = fn (AppreciationEvent $e): array => $this->event($e, $user, $today);

        return view('livewire.humas.apresiasi', [
            'upcoming' => $upcoming->map($map)->all(),
            'overdue' => $overdue->map($map)->all(),
            'done' => $done->map($map)->all(),
            'doneCount' => $doneCount,
            'doneMore' => $this->doneLimit < self::DONE_MAX && $doneCount > $done->count(),
            'canCreate' => $user->can('create', AppreciationEvent::class),
            'minDate' => $today->toDateString(),
            'achievementOptions' => $this->addingEventId !== null ? $this->achievementOptions() : [],
            'confirm' => $this->confirmation(),
        ])->layoutData(['badges' => HumasQueries::badges($user)]);
    }

    /** @return array<string, mixed> */
    private function event(AppreciationEvent $event, User $user, CarbonImmutable $today): array
    {
        $completed = $event->isCompleted();
        $isUpcoming = ! $event->held_on->lessThan($today);
        $rows = $event->appreciations->filter(fn (Appreciation $a): bool => $a->achievement !== null);
        $scheduled = $rows->filter(fn (Appreciation $a): bool => $a->status === AppreciationStatus::Dijadwalkan)->count();
        $canUpdate = $user->can('update', $event);
        $blocker = CompleteAppreciationEvent::blocker($event, $scheduled, $today);

        $chip = match (true) {
            $completed => ['green', 'Sudah dilaksanakan'],
            $event->held_on->equalTo($today) => ['blue', 'Hari ini'],
            $isUpcoming => ['blue', 'Akan datang'],
            default => ['orange', 'Belum dikonfirmasi'],
        };

        return [
            'id' => (string) $event->getKey(),
            'name' => $event->name,
            'date' => HumasQueries::longDate($event->held_on),
            'count' => $rows->count(),
            'chip' => $chip,
            'adding' => $this->addingEventId === (string) $event->getKey(),
            'canAdd' => ! $completed && $isUpcoming && $canUpdate,
            'canComplete' => $canUpdate && $blocker === null,
            // The reason replaces the button, so there is never a dead "Tandai sudah dilaksanakan".
            'completeNote' => $canUpdate ? self::completeNote($blocker, $event, $isUpcoming) : null,
            // Rows already `selesai` are a record too (DeleteAppreciationEvent refuses them).
            'canDelete' => $scheduled === $rows->count() && $user->can('delete', $event),
            'completedNote' => $completed ? self::completedNote($event) : null,
            'items' => $rows
                ->map(function (Appreciation $a) use ($user, $completed, $canUpdate): array {
                    // Consent withdrawn after the row was added: no name/class without `student.view`.
                    $facts = AchievementFacts::of($a->achievement)->visibleTo($user);

                    return [
                        'id' => (string) $a->getKey(),
                        'student' => $facts->studentName,
                        'class' => $facts->className,
                        'headline' => $facts->headline(),
                        'competition' => $facts->competitionName,
                        'consentWithdrawn' => ! $facts->publishConsent,
                        'status' => $a->status === AppreciationStatus::Selesai ? ['green', 'Sudah diapresiasi'] : ['blue', 'Dijadwalkan'],
                        // Same rule as AppreciationPolicy::delete(), from what this list already loaded.
                        'canRelease' => $canUpdate && ! $completed && $a->status === AppreciationStatus::Dijadwalkan,
                    ];
                })
                ->sortBy(fn (array $row): string => mb_strtolower($row['student']))
                ->values()
                ->all(),
        ];
    }

    /** Why "Tandai sudah dilaksanakan" is not offered (rule: CompleteAppreciationEvent::blocker()). */
    private static function completeNote(?string $blocker, AppreciationEvent $event, bool $isUpcoming): ?string
    {
        return match ($blocker) {
            CompleteAppreciationEvent::BLOCK_NOT_YET => 'Bisa ditandai sudah dilaksanakan mulai '.HumasQueries::longDate($event->held_on).'.',
            CompleteAppreciationEvent::BLOCK_EMPTY => $isUpcoming
                ? 'Belum bisa ditandai sudah dilaksanakan: belum ada prestasi di acara ini.'
                : 'Acara ini kosong, jadi tidak bisa ditandai sudah dilaksanakan. Hapus kalau tidak jadi.',
            default => null,
        };
    }

    /** "Dikonfirmasi 5 Okt 2026 oleh Dewi Anggraini". */
    private static function completedNote(AppreciationEvent $event): string
    {
        $by = trim((string) $event->completedBy?->name);

        return 'Dikonfirmasi '.HumasQueries::shortDate($event->completed_at, true).($by !== '' ? ' oleh '.$by : '').'.';
    }

    /**
     * Texts of the confirmation dialog for the chosen event, or null while none is chosen.
     *
     * @return array<string, mixed>|null
     */
    private function confirmation(): ?array
    {
        if ($this->confirmEventId === null) {
            return null;
        }

        $event = AppreciationEvent::query()->withCount('appreciations')->find($this->confirmEventId);

        if ($event === null) {
            return null;
        }

        $count = (int) $event->appreciations_count;
        $delete = $this->confirmAction === self::CONFIRM_DELETE;
        $release = $this->confirmAction === self::CONFIRM_RELEASE;

        return [
            'action' => $this->confirmAction,
            'title' => match (true) {
                $release => 'Lepas dari acara?',
                $delete => 'Hapus acara ini?',
                default => 'Tandai sudah dilaksanakan?',
            },
            'delete' => $delete,
            'name' => $event->name,
            'date' => HumasQueries::longDate($event->held_on),
            'count' => $count,
            // The row a release is about, as the list shows it (no name without consent or `student.view`).
            'row' => $release ? $this->releasing() : null,
            'text' => match (true) {
                $release => 'Prestasi ini dilepas dari acara dan bisa dimasukkan ke acara lain. Acaranya tetap ada. Siswanya diberi tahu.',
                $delete && $count > 0 => $count.' prestasi dilepas dari acara ini dan bisa dimasukkan ke acara lain. Siswanya diberi tahu bahwa acara dibatalkan.',
                $delete => 'Acara ini belum berisi prestasi, jadi tidak ada siswa yang perlu diberi tahu.',
                default => $count.' prestasi ditandai sudah diapresiasi. Setelah itu acara ini tidak bisa diubah atau dihapus lagi.',
            },
        ];
    }

    /** "Raka Pradipta (XI-4) · Juara II Nasional · FIKSI 2026" for the row in the "Lepas dari acara" dialog. */
    private function releasing(): ?string
    {
        $relations = array_map(fn (string $r): string => 'achievement.'.$r, AchievementFacts::RELATIONS);
        $row = $this->confirmRowId !== null ? Appreciation::query()->with($relations)->find($this->confirmRowId) : null;

        if ($row === null || $row->achievement === null) {
            return null;
        }

        $facts = AchievementFacts::of($row->achievement)->visibleTo($this->actor());

        return $facts->studentName.($facts->className !== null ? ' ('.$facts->className.')' : '')
            .' · '.$facts->headline().' · '.$facts->competitionName;
    }

    private function openConfirm(AppreciationEvent $event, string $action): void
    {
        $this->confirmEventId = (string) $event->getKey();
        $this->confirmAction = $action;
        $this->dispatch('sth-modal-open', id: self::CONFIRM_DIALOG);
    }

    private function closeConfirm(): void
    {
        $this->confirmEventId = null;
        $this->confirmRowId = null;
        $this->confirmAction = '';
        $this->dispatch('sth-modal-close', id: self::CONFIRM_DIALOG);
    }

    /**
     * Achievements that are free for an event: verified, with publish consent, and on no event at all. One
     * that is scheduled elsewhere or was already appreciated is not offered (RecordAppreciation would refuse
     * it; M2-B, Q2-10); "Lepas dari acara" or "Hapus acara" brings it back.
     *
     * @return array<string, string> achievement id => "Nama (Kelas) · Juara I Nasional · Lomba"
     */
    private function achievementOptions(): array
    {
        return HumasQueries::appreciableAchievements()
            ->whereDoesntHave('appreciations')
            ->with(['student.user', 'student.classroom', 'competition'])
            ->latest('verified_at')
            ->limit(self::OPTIONS_LIMIT)
            ->get()
            ->mapWithKeys(function (Achievement $achievement): array {
                $name = (string) ($achievement->student?->user?->name ?? 'Siswa');
                // An alumnus shows "Alumni", never the last class (as on the ranking, D-80; QA5).
                $class = $achievement->student?->user?->status === UserStatus::Archived
                    ? 'Alumni'
                    : $achievement->student?->classroom?->name;
                $level = $achievement->level?->shortLabel();

                return [(string) $achievement->getKey() => $name.($class !== null ? ' ('.$class.')' : '')
                    .' · '.trim($achievement->result->label().' '.$level)
                    .' · '.(string) ($achievement->competition?->name ?? 'Lomba')];
            })
            ->all();
    }

    /**
     * The event, or null when a colleague deleted it since the list was drawn (QA9): "Hapus acara" is a hard delete, and
     * findOrFail() answered a full-screen 404. Known as deleted = one of this screen's locked ids (the add form or the
     * open dialog) or an audited `appreciation.event_deleted` of that id; the form or dialog closes and the toast says
     * so. Any other id is still a 404. Events are not per user: every account with `appreciation.manage` sees them all.
     */
    private function findEvent(string $id): ?AppreciationEvent
    {
        $this->authorize('viewAny', AppreciationEvent::class);

        $event = AppreciationEvent::query()->find($id);

        if ($event !== null) {
            return $event;
        }

        abort_unless(in_array($id, [$this->addingEventId, $this->confirmEventId], true) || self::wasDeleted($id), 404);

        if ($this->addingEventId === $id) {
            $this->cancelAdding();
        }

        if ($this->confirmEventId === $id) {
            $this->closeConfirm();
        }

        $this->dispatch('sth-toast', message: 'Acara ini sudah dihapus. Daftar sudah diperbarui.', tone: 'warning');

        return null;
    }

    private static function wasDeleted(string $id): bool
    {
        return Activity::query()
            ->where('event', 'appreciation.event_deleted')
            ->where('subject_type', (new AppreciationEvent)->getMorphClass())
            ->where('subject_id', $id)
            ->exists();
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
