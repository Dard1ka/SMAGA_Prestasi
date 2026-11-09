<?php

declare(strict_types=1);

namespace App\Livewire\Sekolah;

use App\Actions\Registrations\ApproveRevision;
use App\Actions\Registrations\MentorChoice;
use App\Actions\Registrations\RejectRevision;
use App\Actions\Registrations\RevisionRules;
use App\Enums\RevisionStatus;
use App\Enums\RoleName;
use App\Models\Registration;
use App\Models\RegistrationRevision;
use App\Models\Student;
use App\Models\User;
use App\Support\RevisionQueue;
use App\Support\StaffRoleLabel;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * SV8, D-199: "Revisi pendaftaran" for Tata Usaha (`tu.revisi.index`) and Kesiswaan (`kesiswaan.revisi.index`), the
 * same component under each area (RevisionQueue). Revisions the ketua of an approved registration asked for: what they
 * change (students in with their answers, students out, the guru), the reason, whether letters were issued. "Setujui"
 * once every invited student accepted; "Tolak" with a note (5–500) at any time. Both ask first (D-128) and say who is
 * told; the first decision wins, a row decided or cancelled elsewhere since it was shown is answered with a toast and a
 * fresh list (never an error page). Only `registration.revise_decide` (Tata Usaha, Kesiswaan): Admin, the Kepala
 * Sekolah, Humas and a guru get 403. Tabs: Menunggu (ready first, then newest) and Riwayat.
 */
#[Title('Revisi pendaftaran')]
class RevisiPendaftaran extends Component
{
    public const APPROVE_DIALOG = 'revisi-setujui';

    public const REJECT_DIALOG = 'revisi-tolak';

    public const TABS = ['menunggu', 'riwayat'];

    public const STEP = 20;

    public const MAX = 200;

    public const SUBTITLE = 'Revisi tim atau guru pembimbing dari pendaftaran yang sudah disetujui.';

    #[Url(except: 'menunggu')]
    public string $tab = 'menunggu';

    /** @var list<string> revision ids this screen showed during this visit (any other id answers 404) */
    #[Locked]
    public array $shown = [];

    /** The revision the open dialog asks about; set only by askApprove() / askReject(). */
    #[Locked]
    public ?string $deciding = null;

    /** 'setujui' or 'tolak': which question is open. */
    #[Locked]
    public ?string $decision = null;

    public string $note = '';

    public int $limit = self::STEP;

    public function mount(): void
    {
        $this->authorize('viewQueue', RegistrationRevision::class);

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'menunggu';
        }
    }

    public function showMore(): void
    {
        $this->authorize('viewQueue', RegistrationRevision::class);
        $this->limit = min(self::MAX, $this->limit + self::STEP);
    }

    public function askApprove(string $id): void
    {
        $this->ask($id, 'setujui');
    }

    public function askReject(string $id): void
    {
        $this->ask($id, 'tolak');
    }

    /** "Setujui revisi" in the dialog; without the question it does nothing. */
    public function approve(ApproveRevision $approve): void
    {
        if ($this->decision !== 'setujui' || $this->deciding === null) {
            return;
        }

        $revision = $this->findShown($this->deciding);
        $this->resetErrorBag();

        try {
            $approve->handle($this->user(), $revision, $this->note);
        } catch (ValidationException $e) {
            $this->refused($e);

            return;
        }

        $letters = (bool) ($revision->registration()->first()?->letters()->exists());
        $this->close();
        $this->dispatch('sth-toast', message: 'Revisi disetujui. Tim dan guru pembimbing sudah diperbarui; siswa dan guru pembimbing diberi tahu.'
            .($letters ? ' '.$this->koreksiLine() : ''), tone: 'success');
    }

    /** "Tolak revisi" in the dialog; without the question it does nothing. */
    public function reject(RejectRevision $reject): void
    {
        if ($this->decision !== 'tolak' || $this->deciding === null) {
            return;
        }

        $revision = $this->findShown($this->deciding);
        $this->resetErrorBag();

        try {
            $reject->handle($this->user(), $revision, $this->note);
        } catch (ValidationException $e) {
            $this->refused($e);

            return;
        }

        $this->close();
        $this->dispatch('sth-toast', message: 'Revisi ditolak. Ketua tim diberi tahu beserta alasannya.', tone: 'success');
    }

    public function render(): View
    {
        $this->authorize('viewQueue', RegistrationRevision::class);
        $this->limit = max(self::STEP, min(self::MAX, $this->limit));

        $revisions = $this->rows();
        $this->shown = array_values(array_unique([...$this->shown, ...array_map('strval', $revisions->modelKeys())]));
        $names = $this->names($revisions);
        $waiting = RegistrationRevision::query()->awaitingDecision()->count();
        $this->dispatch('sth-badges', badges: [RevisionQueue::BADGE => $waiting]);

        $rows = $revisions->map(fn (RegistrationRevision $r): array => $this->row($r, $names))->all();

        return view('livewire.sekolah.revisi-pendaftaran', [
            'rows' => $rows,
            'counts' => [
                'menunggu' => $this->listed()->pending()->count(),
                'riwayat' => $this->listed()->where('status', '!=', RevisionStatus::Pending->value)->count(),
            ],
            'more' => $this->listedForTab()->count() > count($rows) && $this->limit < self::MAX,
            'dialog' => $this->dialog($rows),
            'routeName' => RevisionQueue::routeName($this->user()),
        ])->layoutData([
            'subtitle' => self::SUBTITLE,
            'badges' => [RevisionQueue::BADGE => $waiting],
        ]);
    }

    // Rows --------------------------------------------------------------------------------------

    /**
     * @param  array{students: \Illuminate\Support\Collection<string, Student>, gurus: \Illuminate\Support\Collection<int, User>}  $names
     * @return array<string, mixed>
     */
    private function row(RegistrationRevision $revision, array $names): array
    {
        /** @var Registration $registration */
        $registration = $revision->registration;
        $student = fn (string $id): string => (string) ($names['students']->get($id)?->user?->name ?? 'Siswa')
            .($names['students']->get($id)?->classroom !== null ? ' ('.$names['students']->get($id)->classroom->name.')' : '');
        $guru = fn (?int $id): ?string => $id === null ? null : (MentorChoice::name($names['gurus']->get($id)) ?? 'Guru');
        $problem = match (true) {
            $registration->leader === null => ApproveRevision::MSG_NOT_LISTED,
            ! RevisionRules::isAfterApproval($registration) => RevisionRules::closedReason($registration) ?? RevisionRules::MSG_NOT_APPROVED,
            // 2026-11-09: the guru changed since the revision was asked for; ApproveRevision refuses it.
            ApproveRevision::mentorChangedSince($revision, $registration) => ApproveRevision::MSG_MENTOR_STALE,
            default => null,
        };
        $leader = $registration->leader;

        return [
            'id' => (string) $revision->getKey(),
            'lomba' => (string) $registration->competition?->name,
            'leader' => trim((string) $leader?->user?->name.($leader?->classroom !== null ? ' ('.$leader->classroom->name.')' : '')),
            'registrationStatus' => [$registration->status->tone(), $registration->status->label()],
            'status' => [$revision->status->tone(), $revision->status->label()],
            'pending' => $revision->isPending(),
            'ready' => $revision->isPending() && $revision->everyoneConfirmed(),
            'problem' => $revision->isPending() ? $problem : null,
            'letters' => (bool) $registration->getAttribute('has_letter'),
            'added' => array_map(fn (string $id): array => [
                'name' => $student($id),
                'state' => match ($revision->answerOf($id)) {
                    'confirmed' => ['Sudah menerima', 'green'],
                    'declined' => ['Menolak', 'red'],
                    default => ['Menunggu jawaban', 'gray'],
                },
            ], $revision->additions()),
            'removed' => array_map($student, $revision->removals()),
            'mentorFrom' => $guru($revision->mentorFrom()) ?? ($revision->mentorTo() !== null ? 'belum ada' : null),
            'mentorTo' => $guru($revision->mentorTo()),
            'reason' => $revision->reason,
            'sentOn' => $revision->created_at?->locale('id')->translatedFormat('j M Y, H.i'),
            'decided' => $revision->isPending() ? null : trim(($revision->decided_at?->locale('id')->translatedFormat('j M Y, H.i') ?? '')
                .($revision->decider !== null ? ' · '.$revision->decider->name.' ('.$this->roleOf($revision->decider).')' : '')),
            'note' => $revision->note,
        ];
    }

    /**
     * The open question (D-128): who is told, and the Koreksi line when letters were issued. Null when nothing is asked
     * or the row left the list.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function dialog(array $rows): ?array
    {
        if ($this->deciding === null) {
            return null;
        }

        $row = collect($rows)->firstWhere('id', $this->deciding);

        if ($row === null || ! $row['pending']) {
            return null;
        }

        return [
            'row' => $row,
            'notices' => $this->decision === 'setujui'
                ? array_values(array_filter([
                    'Tim dan guru pembimbing langsung diperbarui.',
                    'Ketua tim, anggota, siswa yang ditambah atau dikeluarkan, dan guru pembimbing diberi tahu. Guru pembimbing yang diganti tidak diberi tahu.',
                    $row['letters'] ? 'Surat tugas yang sudah terbit tidak berubah. '.$this->koreksiLine() : null,
                ]))
                : [
                    'Tim yang sudah disetujui tetap berlaku.',
                    'Ketua tim diberi tahu beserta alasannya; siswa yang diundang diberi tahu bahwa mereka tidak masuk tim.',
                ],
        ];
    }

    /** Tata Usaha reads its own step, Kesiswaan who does it (D-119: no pronoun, no third person for the reader). */
    private function koreksiLine(): string
    {
        return $this->user()->hasRole(RoleName::TataUsaha->value)
            ? 'Buat Koreksi surat tugasnya di Antrean surat.'
            : 'Tata Usaha diberi tahu untuk membuat Koreksi surat tugasnya.';
    }

    /** @return Collection<int, RegistrationRevision> */
    private function rows(): Collection
    {
        return $this->listedForTab()
            ->with([
                'registration' => fn ($q) => $q->withExists('letters as has_letter'),
                'registration.competition',
                'registration.leader.user:id,name',
                'registration.leader.classroom:id,name',
                'decider.roles',
            ])
            ->limit($this->limit)
            ->get();
    }

    /** @return Builder<RegistrationRevision> */
    private function listedForTab(): Builder
    {
        return $this->tab === 'riwayat'
            ? $this->listed()->where('status', '!=', RevisionStatus::Pending->value)->orderByDesc('decided_at')->orderByDesc('id')
            : $this->listed()->pending()
                ->orderByRaw('case when ready_at is null then 1 else 0 end')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
    }

    /**
     * Revisions of registrations whose ketua is not in the trash (Registration::scopeWithListedLeader(), the rule of
     * every staff queue).
     *
     * @return Builder<RegistrationRevision>
     */
    private function listed(): Builder
    {
        return RegistrationRevision::query()->whereHas('registration', fn (Builder $r) => $r->withListedLeader());
    }

    /**
     * One query for every student and guru the rows name.
     *
     * @param  Collection<int, RegistrationRevision>  $revisions
     * @return array{students: \Illuminate\Support\Collection<string, Student>, gurus: \Illuminate\Support\Collection<int, User>}
     */
    private function names(Collection $revisions): array
    {
        $studentIds = $revisions->flatMap(fn (RegistrationRevision $r): array => [...$r->additions(), ...$r->removals()])->unique()->values()->all();
        $guruIds = $revisions->flatMap(fn (RegistrationRevision $r): array => array_filter([$r->mentorFrom(), $r->mentorTo()]))->unique()->values()->all();

        return [
            'students' => $studentIds === [] ? collect() : Student::query()->withTrashed()->whereKey($studentIds)
                ->with(['user:id,name', 'classroom:id,name'])->get()->keyBy(fn (Student $s): string => (string) $s->getKey()),
            'gurus' => $guruIds === [] ? collect() : User::query()->whereKey($guruIds)->with('staffProfile')->get()
                ->keyBy(fn (User $u): int => (int) $u->getKey()),
        ];
    }

    // Helpers -----------------------------------------------------------------------------------

    private function ask(string $id, string $decision): void
    {
        $this->resetErrorBag();
        $this->deciding = null;
        $this->decision = null;
        $this->note = '';
        $revision = $this->findShown($id);

        if (! $revision->isPending()) {
            $this->dispatch('sth-toast', message: ApproveRevision::decidedText($revision).' Daftar sudah diperbarui.', tone: 'warning');

            return;
        }

        $this->authorize('decide', $revision);

        if ($decision === 'setujui' && ! $revision->everyoneConfirmed()) {
            $this->dispatch('sth-toast', message: ApproveRevision::MSG_WAITING.' Daftar sudah diperbarui.', tone: 'warning');

            return;
        }

        if ($decision === 'setujui' && $revision->registration !== null && ApproveRevision::mentorChangedSince($revision, $revision->registration)) {
            $this->dispatch('sth-toast', message: ApproveRevision::MSG_MENTOR_CHANGED.' Daftar sudah diperbarui.', tone: 'warning');

            return;
        }

        $this->deciding = (string) $revision->getKey();
        $this->decision = $decision;
        $this->dispatch('sth-modal-open', id: $decision === 'setujui' ? self::APPROVE_DIALOG : self::REJECT_DIALOG);
    }

    /** A note error stays in the dialog; anything else (decided elsewhere, the registration moved on) is a toast. */
    private function refused(ValidationException $e): void
    {
        $errors = $e->errors();

        if (isset($errors['note'])) {
            $this->addError('note', (string) $errors['note'][0]);

            return;
        }

        $this->close();
        $this->dispatch('sth-toast', message: (string) collect($errors)->flatten()->first().' Daftar sudah diperbarui.', tone: 'warning');
    }

    private function close(): void
    {
        $id = $this->decision === 'setujui' ? self::APPROVE_DIALOG : self::REJECT_DIALOG;
        $this->deciding = null;
        $this->decision = null;
        $this->note = '';
        $this->dispatch('sth-modal-close', id: $id);
    }

    /** A revision this screen showed (any other id → 404). */
    private function findShown(string $id): RegistrationRevision
    {
        $this->authorize('viewQueue', RegistrationRevision::class);
        abort_unless(in_array($id, $this->shown, true), 404);

        $revision = RegistrationRevision::query()->find($id);
        abort_if($revision === null, 404);

        return $revision;
    }

    private function roleOf(User $user): string
    {
        return StaffRoleLabel::forDecision($user);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
