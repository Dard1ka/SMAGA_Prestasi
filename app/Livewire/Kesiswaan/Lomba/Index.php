<?php

declare(strict_types=1);

namespace App\Livewire\Kesiswaan\Lomba;

use App\Actions\Competitions\CloseCompetition;
use App\Actions\Competitions\CompetitionDates;
use App\Actions\Competitions\VerifyCompetition;
use App\Enums\CompetitionStatus;
use App\Enums\Participation;
use App\Enums\RegistrationStatus;
use App\Enums\RoleName;
use App\Http\Controllers\FileController;
use App\Livewire\Kesiswaan\Concerns\WithKesiswaanBadges;
use App\Models\Competition;
use App\Models\RegistrationMember;
use App\Models\User;
use App\Rules\ExternalUrl;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Kelola lomba & antrean verifikasi (UI-MAP #16, board Kesiswaan-Kelola-Lomba). Kesiswaan + Admin.
 * Tab "katalog" lists every lomba (all statuses); tab "verifikasi" lists every `menunggu_verifikasi`
 * upload from any role, Kesiswaan's own included (D-27). The drawer form is the child Lomba\Form.
 */
#[Title('Kelola lomba')]
#[Layout('layouts::app', ['subtitle' => 'Lomba yang dipublikasikan tampil di katalog siswa.'])]
class Index extends Component
{
    use WithKesiswaanBadges;

    private const PAGE = 25;

    /** Upper bound of `$limit` (a public property the browser can set): beyond it the user narrows the filter. */
    private const MAX = 250;

    #[Url(as: 'tab', except: 'katalog')]
    public string $tab = 'katalog';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    #[Url(as: 'status', except: '')]
    public string $status = '';

    public int $limit = self::PAGE;

    #[Locked]
    public ?string $rejectingId = null;

    public string $rejectNote = '';

    /** id of the dialog "Tanggal lomba ini sudah lewat. Tetap publikasikan?" (<x-sth.modal>). */
    public const PAST_DIALOG = 'lomba-tanggal-lewat';

    /** Lomba waiting in that dialog; set only by publish(). */
    #[Locked]
    public ?string $pastId = null;

    /** id of the dialog "Tutup lomba ini?" (<x-sth.modal>; D-128: the app's own dialog, not the browser's confirm). */
    public const CLOSE_DIALOG = 'tutup-lomba';

    /** Lomba waiting in that dialog; set only by askClose(). */
    #[Locked]
    public ?string $closingId = null;

    public function mount(): void
    {
        $this->authorize('manage', Competition::class);

        if (! in_array($this->tab, ['katalog', 'verifikasi'], true)) {
            $this->tab = 'katalog';
        }

        if ($this->status !== '' && CompetitionStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['katalog', 'verifikasi'], true) ? $tab : 'katalog';
        $this->cancelReject();
    }

    public function setStatus(string $status): void
    {
        $this->status = CompetitionStatus::tryFrom($status) !== null ? $status : '';
        $this->limit = self::PAGE;
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr(trim($this->q), 0, 100);
        $this->limit = self::PAGE;
    }

    public function updatedLimit(): void
    {
        $this->clampLimit();
    }

    public function showMore(): void
    {
        $this->limit += self::PAGE;
        $this->clampLimit();
    }

    /** "Tambah lomba": Admin only since D-145 (siswa propose through Usulkan lomba). */
    public function create(): void
    {
        $this->authorize('manage', Competition::class);
        $this->authorize('create', Competition::class);
        $this->dispatch('lomba-form-open', id: null, mode: 'create');
    }

    public function edit(string $id): void
    {
        $competition = $this->find($id);

        if ($this->changedElsewhere($competition, CompetitionStatus::MenungguVerifikasi, CompetitionStatus::Dipublikasikan)) {
            return;
        }

        $this->authorize('update', $competition);
        $this->dispatch('lomba-form-open', id: $competition->getKey(), mode: $competition->status === CompetitionStatus::MenungguVerifikasi ? 'verify' : 'edit');
    }

    /**
     * "Publikasikan" on a queue card. A lomba whose registration deadline or final date has passed is not
     * published by this click: the dialog PAST_DIALOG asks first (M2-B, Q1-04) and publishPast() carries on.
     */
    public function publish(string $id, VerifyCompetition $verify): void
    {
        $competition = $this->find($id);

        if ($this->changedElsewhere($competition, CompetitionStatus::MenungguVerifikasi)) {
            return;
        }

        $this->authorize('verify', $competition);

        if (CompetitionDates::past($competition->registration_closes_at, $competition->final_on) !== []) {
            $this->askPast($competition);

            return;
        }

        $this->runPublish($competition, false, $verify);
    }

    /** "Tetap publikasikan" in the dialog: the verifier said yes to the past dates. */
    public function publishPast(VerifyCompetition $verify): void
    {
        abort_if($this->pastId === null, 404);

        $competition = $this->find($this->pastId);
        $this->pastId = null;
        $this->dispatch('sth-modal-close', id: self::PAST_DIALOG);

        if ($this->changedElsewhere($competition, CompetitionStatus::MenungguVerifikasi)) {
            return;
        }

        $this->authorize('verify', $competition);
        $this->runPublish($competition, true, $verify);
    }

    private function runPublish(Competition $competition, bool $confirmPastDates, VerifyCompetition $verify): void
    {
        try {
            $verify->handle($this->actor(), $competition, CompetitionStatus::Dipublikasikan, null, $confirmPastDates);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            // The date passed between drawing the card and the click: ask, do not refuse.
            if (isset($errors[CompetitionDates::PAST_KEY]) && ! $confirmPastDates) {
                $this->askPast($competition);

                return;
            }

            $this->dispatch('sth-toast', message: collect($errors)->flatten()->first() ?? 'Lomba belum bisa dipublikasikan.', tone: 'error');

            return;
        }

        $this->dispatch('sth-toast', message: 'Lomba dipublikasikan ke katalog.', tone: 'success');
    }

    private function askPast(Competition $competition): void
    {
        $this->pastId = $competition->getKey();
        $this->dispatch('sth-modal-open', id: self::PAST_DIALOG);
    }

    public function startReject(string $id): void
    {
        $competition = $this->find($id);

        if ($this->changedElsewhere($competition, CompetitionStatus::MenungguVerifikasi)) {
            return;
        }

        $this->authorize('verify', $competition);

        $this->rejectingId = $competition->getKey();
        $this->rejectNote = '';
        $this->resetErrorBag();
    }

    public function cancelReject(): void
    {
        $this->rejectingId = null;
        $this->rejectNote = '';
        $this->resetErrorBag();
    }

    public function reject(VerifyCompetition $verify): void
    {
        $this->resetErrorBag();

        if ($this->rejectingId === null) {
            return;
        }

        $competition = $this->find($this->rejectingId);

        if ($this->changedElsewhere($competition, CompetitionStatus::MenungguVerifikasi)) {
            $this->cancelReject();

            return;
        }

        try {
            $verify->handle($this->actor(), $competition, CompetitionStatus::Ditolak, $this->rejectNote);
        } catch (ValidationException $e) {
            $this->addError('rejectNote', collect($e->errors())->flatten()->first() ?? 'Lomba ini belum bisa ditolak. Coba lagi.');

            return;
        }

        // The reason is optional (D-103): the toast only promises a reason when one was written, and only says the
        // submitter was told when a notification really went out (not the verifier's own entry, QA5).
        $withReason = trim($this->rejectNote) !== '';
        $told = VerifyCompetition::notifiesSubmitter($competition->submitter()->first(), $this->actor());

        $this->cancelReject();
        $this->dispatch('sth-toast', message: match (true) {
            ! $told => 'Lomba ditolak.',
            $withReason => 'Lomba ditolak. Pengirim menerima alasannya.',
            default => 'Lomba ditolak. Pengirim diberi tahu.',
        }, tone: 'success');
    }

    /**
     * "Tutup" on a catalog row (D-128): opens the dialog. A closed lomba leaves the student catalog and nothing opens
     * it again, so nothing is closed before close().
     */
    public function askClose(string $id): void
    {
        $this->closingId = null;
        $competition = $this->find($id);

        if ($this->changedElsewhere($competition, CompetitionStatus::Dipublikasikan)) {
            return;
        }

        $this->authorize('close', $competition);

        $this->closingId = $competition->getKey();
        $this->dispatch('sth-modal-open', id: self::CLOSE_DIALOG);
    }

    /**
     * "Tutup lomba" in the dialog. Without a dialog opened by askClose() there is nothing to confirm: it returns
     * without a word (never an error page), so a second click after the lomba was closed changes and says nothing.
     */
    public function close(CloseCompetition $close): void
    {
        $id = $this->closingId;
        $this->closingId = null;
        $this->dispatch('sth-modal-close', id: self::CLOSE_DIALOG);

        if ($id === null) {
            return;
        }

        $competition = $this->find($id);

        if ($this->changedElsewhere($competition, CompetitionStatus::Dipublikasikan)) {
            return;
        }

        try {
            $close->handle($this->actor(), $competition);
        } catch (ValidationException $e) {
            $this->dispatch('sth-toast', message: collect($e->errors())->flatten()->first() ?? 'Lomba tidak bisa ditutup.', tone: 'error');

            return;
        }

        $this->dispatch('sth-toast', message: 'Lomba ditutup dan tidak tampil lagi di katalog.', tone: 'success');
    }

    #[On('lomba-saved')]
    public function refreshAfterSave(): void
    {
        // Re-render only; the form child already showed its toast.
    }

    public function render(): View
    {
        $this->clampLimit();

        $now = CarbonImmutable::now();
        $pending = Competition::query()->pendingVerification()->count();
        $more = $this->tab === 'katalog' && $this->catalogQuery()->count() > $this->limit;

        return view('livewire.kesiswaan.lomba.index', [
            'stats' => $this->stats($now, $pending),
            'pendingCount' => $pending,
            'catalogCount' => Competition::query()->count(),
            'rows' => $this->tab === 'katalog' ? $this->catalogRows($now) : collect(),
            'hasMore' => $more && $this->limit < self::MAX,
            'capped' => $more && $this->limit >= self::MAX,
            'queue' => $this->tab === 'verifikasi' ? $this->queueCards() : collect(),
            'statusFilters' => ['' => 'Semua'] + CompetitionStatus::options(),
            'pastLomba' => $this->pastLomba(),
            'closingLomba' => $this->closingLomba(),
            // "Tambah lomba" is Admin only (D-145): Kesiswaan checks and corrects, it never adds a lomba.
            'canCreate' => Auth::user()?->can('create', Competition::class) ?? false,
            // One search box per screen (D-90, backlog #60): this page has its own, so the top bar shows none.
        ])->layoutData(['badges' => $this->kesiswaanBadges(), 'search' => false]);
    }

    /** @return array{name: string, dates: string}|null the lomba in the "Tetap publikasikan?" dialog */
    private function pastLomba(): ?array
    {
        $competition = $this->pastId !== null ? Competition::query()->find($this->pastId) : null;

        if ($competition === null) {
            return null;
        }

        return [
            'name' => (string) $competition->name,
            'dates' => implode(', ', CompetitionDates::past($competition->registration_closes_at, $competition->final_on)),
        ];
    }

    /** @return array{name: string, count: int}|null the lomba in the "Tutup lomba ini?" dialog and its registered students */
    private function closingLomba(): ?array
    {
        $competition = $this->closingId !== null ? Competition::query()->find($this->closingId) : null;

        if ($competition === null) {
            return null;
        }

        return [
            'name' => (string) $competition->name,
            'count' => (int) ($this->studentCounts([$competition->getKey()])[$competition->getKey()] ?? 0),
        ];
    }

    /** @return Builder<Competition> */
    private function catalogQuery(): Builder
    {
        return Competition::query()
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->when($this->q !== '', function (Builder $q): void {
                $term = '%'.addcslashes($this->q, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $term)->orWhere('organizer_name', 'like', $term));
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function catalogRows(CarbonImmutable $now): Collection
    {
        $competitions = $this->catalogQuery()
            ->orderByRaw('case when status = ? and registration_closes_at >= ? then 0 when status = ? then 1 when status = ? then 2 else 3 end', [
                CompetitionStatus::Dipublikasikan->value, $now->toDateTimeString(),
                CompetitionStatus::MenungguVerifikasi->value, CompetitionStatus::Dipublikasikan->value,
            ])
            // FS1 (D-135): pending lomba oldest first.
            ->orderByRaw('case when status = ? then created_at end', [CompetitionStatus::MenungguVerifikasi->value])
            ->orderByRaw('case when registration_closes_at is null then 1 else 0 end')
            ->orderBy('registration_closes_at')
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get();

        $counts = $this->studentCounts($competitions->modelKeys());
        $user = $this->actor();

        return $competitions->map(function (Competition $c) use ($now, $counts, $user): array {
            $closes = $c->registration_closes_at;
            $daysLeft = $closes !== null ? (int) $now->startOfDay()->diffInDays($closes->startOfDay(), false) : null;

            [$tone, $icon, $label] = match (true) {
                $c->status === CompetitionStatus::Dipublikasikan && $c->registration_opens_at !== null && $c->registration_opens_at > $now => ['blue', 'clock', 'Segera dibuka'],
                $c->status === CompetitionStatus::Dipublikasikan && $closes !== null && $closes < $now => ['gray', 'check', 'Pendaftaran selesai'],
                $c->status === CompetitionStatus::Dipublikasikan => ['green', 'check', 'Dipublikasikan'],
                $c->status === CompetitionStatus::MenungguVerifikasi => ['gray', 'clock', 'Menunggu verifikasi'],
                $c->status === CompetitionStatus::Ditolak => ['red', 'x', 'Ditolak'],
                default => ['gray', 'archive', $c->status->label()],
            };

            return [
                'id' => $c->getKey(),
                'name' => $c->name,
                'sub' => self::subline($c),
                'bidang' => $c->bidang,
                'tingkat' => $c->level?->shortLabel() ?? '–',
                'batas' => $c->registration_opens_at !== null && $c->registration_opens_at > $now
                    ? 'Dibuka '.self::shortDate($c->registration_opens_at)
                    : self::shortDate($closes),
                'soon' => $c->status === CompetitionStatus::Dipublikasikan && $daysLeft !== null && $daysLeft >= 0 && $daysLeft <= 3
                    ? ($daysLeft === 0 ? 'Hari ini' : $daysLeft.' hari lagi')
                    : null,
                'count' => (int) ($counts[$c->getKey()] ?? 0),
                'status' => [$tone, $icon, $label],
                'canEdit' => $user->can('update', $c),
                'canClose' => $user->can('close', $c),
            ];
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function queueCards(): Collection
    {
        $user = $this->actor();

        return Competition::query()
            ->pendingVerification()
            ->with(['submitter.roles', 'submitter.student.classroom', 'juknisFile'])
            ->oldest()
            ->get()
            ->map(function (Competition $c) use ($user): array {
                $submitter = $c->submitter;
                $missing = VerifyCompetition::missingFields($c);
                // Submitter-supplied link (SECURITY §3m-1): linked only while it still passes the rule, and
                // shown by its punycode host so a look-alike IDN domain is visible to the verifier.
                $website = is_string($c->website_url) && ExternalUrl::passes($c->website_url) ? $c->website_url : null;

                return [
                    'id' => $c->getKey(),
                    'name' => $c->name,
                    'bidang' => $c->bidang,
                    'organizer' => $c->organizer_name,
                    'submitter' => $submitter?->name ?? 'Akun dihapus',
                    'submitterMeta' => self::submitterMeta($submitter),
                    'source' => self::sourceLabel($submitter, $user),
                    'when' => self::whenLabel($c->created_at),
                    'website' => $website,
                    'websiteHost' => $website !== null ? ExternalUrl::host($website) : null,
                    'websiteInvalid' => $website === null && (string) $c->website_url !== '',
                    'juknis' => $c->juknisFile !== null ? [
                        'name' => $c->juknisFile->original_name,
                        'size' => self::fileSize($c->juknisFile->size),
                        'url' => Route::has('files.show') ? route('files.show', $c->juknisFile) : null,
                        // M3-C: the click opens the preview popup; the href stays the download.
                        'preview' => FileController::previewUrl($c->juknisFile),
                        'previewType' => FileController::previewType($c->juknisFile),
                    ] : null,
                    'missing' => array_values($missing),
                    // Q1-04: wrong order blocks publishing; dates in the past only ask for a confirmation.
                    'dateProblem' => CompetitionDates::startsBeforeDeadline($c->starts_on, $c->registration_closes_at)
                        ? CompetitionDates::STARTS_BEFORE_DEADLINE
                        : null,
                    'past' => array_values(CompetitionDates::past($c->registration_closes_at, $c->final_on)),
                    'canVerify' => $user->can('verify', $c),
                    'canEdit' => $user->can('update', $c),
                ];
            });
    }

    /**
     * A member row a student knows about (FS1 fix wave): not an anggota of a team that waits for its lomba
     * (`menunggu_lomba`, D-148) and was not invited yet (`invited_at` null), as every student screen hides them.
     *
     * @param  Builder<RegistrationMember>  $query  joined with `registrations`
     */
    private static function knownMember(Builder $query): void
    {
        $query->whereNotNull('registration_members.invited_at')
            ->orWhere('registrations.status', '!=', RegistrationStatus::MenungguLomba->value);
    }

    /**
     * @param  list<string>  $competitionIds
     * @return array<string, int> competition id → distinct students in live registrations (knownMember())
     */
    private function studentCounts(array $competitionIds): array
    {
        if ($competitionIds === []) {
            return [];
        }

        return RegistrationMember::query()
            ->join('registrations', 'registrations.id', '=', 'registration_members.registration_id')
            ->whereIn('registrations.competition_id', $competitionIds)
            ->whereNull('registrations.deleted_at')
            ->whereNotIn('registrations.status', [RegistrationStatus::Dibatalkan->value, RegistrationStatus::Ditolak->value])
            ->whereNull('registration_members.declined_at')
            ->where(self::knownMember(...))
            ->groupBy('registrations.competition_id')
            ->selectRaw('registrations.competition_id as competition_id, count(distinct registration_members.student_id) as n')
            ->pluck('n', 'competition_id')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function stats(CarbonImmutable $now, int $pending): array
    {
        $active = Competition::query()->openForRegistration($now)->count();

        $monthRegistrations = RegistrationMember::query()
            ->join('registrations', 'registrations.id', '=', 'registration_members.registration_id')
            ->whereNull('registrations.deleted_at')
            ->whereNotIn('registrations.status', [RegistrationStatus::Dibatalkan->value])
            ->whereNull('registration_members.declined_at')
            ->where(self::knownMember(...))
            ->where('registrations.created_at', '>=', $now->subDays(30)->startOfDay()->toDateTimeString());
        $students = (clone $monthRegistrations)->distinct()->count('registration_members.student_id');
        $competitions = (clone $monthRegistrations)->distinct()->count('registrations.competition_id');

        $closingSoon = Competition::query()
            ->published()
            ->whereBetween('registration_closes_at', [$now->toDateTimeString(), $now->addDays(7)->endOfDay()->toDateTimeString()])
            ->orderBy('registration_closes_at')
            ->get(['id', 'name', 'registration_closes_at']);
        $next = $closingSoon->first();

        return [
            ['icon' => 'trophy', 'tone' => 'blue', 'label' => 'Lomba aktif', 'value' => (string) $active, 'note' => 'pendaftaran dibuka', 'href' => null],
            ['icon' => 'users', 'tone' => 'green', 'label' => 'Pendaftar 30 hari', 'value' => (string) $students, 'note' => 'siswa di '.$competitions.' lomba', 'href' => null],
            ['icon' => 'inbox', 'tone' => 'orange', 'label' => 'Verifikasi usulan lomba', 'value' => (string) $pending, 'note' => 'menunggu diperiksa', 'href' => route('kesiswaan.lomba.index', ['tab' => 'verifikasi'])],
            ['icon' => 'clock', 'tone' => 'red', 'label' => 'Tutup minggu ini', 'value' => (string) $closingSoon->count(),
                'note' => $next !== null ? mb_strimwidth($next->name, 0, 22, '…').' · '.self::shortDate($next->registration_closes_at, false) : 'tidak ada', 'href' => null],
        ];
    }

    private static function subline(Competition $c): string
    {
        $parts = array_filter([
            $c->organizer_name,
            $c->weight_category?->label(),
            match ($c->participation) {
                Participation::Tim => $c->max_team_size !== null ? 'Tim maks '.$c->max_team_size : 'Tim',
                Participation::Keduanya => 'Individu/Tim',
                Participation::Individu => 'Individu',
                default => null,
            },
        ]);

        return $parts === [] ? 'Data belum lengkap' : implode(' · ', $parts);
    }

    private static function submitterMeta(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        if ($user->student !== null) {
            return 'Siswa'.($user->student->classroom !== null ? ' · '.$user->student->classroom->name : '');
        }

        $role = $user->roles->first()?->name;

        return $role !== null && RoleName::tryFrom($role) !== null ? RoleName::from($role)->label() : 'Staf';
    }

    /** @return array{0: string, 1: string} tone, label */
    private static function sourceLabel(?User $submitter, User $viewer): array
    {
        return match (true) {
            $submitter === null => ['gray', 'Upload'],
            $submitter->is($viewer) => ['blue', 'Upload sendiri'],
            $submitter->student !== null => ['blue', 'Usulan siswa'],
            $submitter->hasRole(RoleName::Guru->value) => ['blue', 'Upload guru'],
            default => ['blue', 'Upload staf'],
        };
    }

    /**
     * Another Kesiswaan or Admin account published, rejected or closed this lomba since the card was drawn (QA9): the
     * policy (verify / update / close) refuses every state but the expected one, which Livewire showed as a full-screen
     * 403. Say what happened instead; the render after this call draws the card as it is now. The screen permission is
     * checked first, and a lomba in an expected state still goes through its policy.
     */
    private function changedElsewhere(Competition $competition, CompetitionStatus ...$expected): bool
    {
        $this->authorize('manage', Competition::class);

        if (in_array($competition->status, $expected, true)) {
            return false;
        }

        $message = match (true) {
            $expected === [CompetitionStatus::MenungguVerifikasi] => 'Lomba ini sudah diputuskan.',
            $competition->status === CompetitionStatus::Ditolak => 'Lomba ini sudah ditolak.',
            $competition->status === CompetitionStatus::Ditutup => 'Lomba ini sudah ditutup.',
            default => 'Status lomba ini sudah berubah.',
        };

        $this->dispatch('sth-toast', message: $message.' Daftar sudah diperbarui.', tone: 'warning');

        return true;
    }

    private function clampLimit(): void
    {
        $this->limit = max(self::PAGE, min(self::MAX, $this->limit));
    }

    private function find(string $id): Competition
    {
        $competition = Competition::query()->find($id);

        abort_if($competition === null, 404);

        return $competition;
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
